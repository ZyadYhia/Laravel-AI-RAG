<?php

use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;

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
