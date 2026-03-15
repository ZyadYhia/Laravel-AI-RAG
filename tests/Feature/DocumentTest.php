<?php

use App\AI\Services\OllamaEmbeddingService;
use App\Events\DocumentsEmbedded;
use App\Events\DocumentsEmbedding;
use App\Jobs\EmbedDocumentChunks;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

test('guests cannot access documents page', function () {
    $this->get(route('documents.index'))->assertRedirect(route('login'));
});

test('authenticated users can view documents page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('documents.index'))
        ->assertOk();
});

test('document upload requires files', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('documents.store'), [])
        ->assertSessionHasErrors('files');
});

test('document upload rejects invalid file types', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->create('malware.exe', 100);

    $this->actingAs($user)
        ->post(route('documents.store'), ['files' => [$file]])
        ->assertSessionHasErrors('files.0');
});

test('users can delete their own documents', function () {
    $user = User::factory()->create();
    Document::factory()->count(3)->create([
        'user_id' => $user->id,
        'source' => 'test.txt',
    ]);

    $this->actingAs($user)
        ->delete(route('documents.destroy', 'test.txt'))
        ->assertRedirect();

    expect(Document::where('user_id', $user->id)->count())->toBe(0);
});

test('users cannot see other users documents', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    Document::factory()->create(['user_id' => $user1->id, 'source' => 'user1.txt']);
    Document::factory()->create(['user_id' => $user2->id, 'source' => 'user2.txt']);

    $response = $this->actingAs($user1)
        ->get(route('documents.index'));

    $response->assertOk();

    $documents = $response->original->getData()['page']['props']['documents'];
    $sources = collect($documents)->pluck('source')->all();

    expect($sources)->toContain('user1.txt')
        ->and($sources)->not->toContain('user2.txt');
});

test('document upload dispatches embed job', function () {
    Queue::fake();

    $user = User::factory()->create();
    $file = UploadedFile::fake()->createWithContent('test.txt', 'Hello world this is a test document.');

    $this->actingAs($user)
        ->post(route('documents.store'), ['files' => [$file]])
        ->assertRedirect()
        ->assertSessionHas('status');

    Queue::assertPushed(EmbedDocumentChunks::class, function ($job) use ($user) {
        return $job->userId === $user->id
            && $job->source === 'test.txt'
            && count($job->chunks) > 0;
    });
});

test('embed document chunks job creates documents with batch embeddings', function () {
    Event::fake();

    $user = User::factory()->create();
    $chunks = ['chunk one content here', 'chunk two content here'];
    $fakeEmbeddings = [
        array_fill(0, 4096, 0.1),
        array_fill(0, 4096, 0.2),
    ];

    $embeddingService = Mockery::mock(OllamaEmbeddingService::class);
    $embeddingService->shouldReceive('embedMany')
        ->once()
        ->with($chunks)
        ->andReturn($fakeEmbeddings);

    $this->app->instance(OllamaEmbeddingService::class, $embeddingService);

    $job = new EmbedDocumentChunks($user->id, $chunks, 'test.txt');
    $job->handle($embeddingService);

    expect(Document::where('user_id', $user->id)->count())->toBe(2);
    expect(Document::where('chunk_index', 0)->first()->content)->toBe('chunk one content here');
    expect(Document::where('chunk_index', 1)->first()->content)->toBe('chunk two content here');

    Event::assertDispatched(DocumentsEmbedding::class, function ($event) use ($user) {
        return $event->userId === $user->id
            && $event->source === 'test.txt'
            && $event->totalChunks === 2;
    });

    Event::assertDispatched(DocumentsEmbedded::class, function ($event) use ($user) {
        return $event->userId === $user->id
            && $event->source === 'test.txt'
            && $event->chunks === 2;
    });
});
