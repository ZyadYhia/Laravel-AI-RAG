<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function createConversation(int $userId, string $title = 'Test conversation'): string
{
    $id = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $id,
        'user_id' => $userId,
        'title' => $title,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function createMessage(string $conversationId, int $userId, string $role, string $content): void
{
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'user_id' => $userId,
        'agent' => 'App\\AI\\Agents\\RagAgent',
        'role' => $role,
        'content' => $content,
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('guests cannot access conversations', function () {
    $this->getJson(route('conversations.index'))->assertUnauthorized();
});

test('authenticated users can list their conversations', function () {
    $user = User::factory()->create();
    createConversation($user->id, 'My chat');

    $response = $this->actingAs($user)
        ->getJson(route('conversations.index'));

    $response->assertSuccessful();
    $data = $response->json();
    expect($data)->toHaveCount(1);
    expect($data[0]['title'])->toBe('My chat');
});

test('users cannot see other users conversations', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    createConversation($other->id, 'Other chat');

    $response = $this->actingAs($user)
        ->getJson(route('conversations.index'));

    $response->assertSuccessful();
    expect($response->json())->toHaveCount(0);
});

test('conversations are ordered by most recent', function () {
    $user = User::factory()->create();

    DB::table('agent_conversations')->insert([
        'id' => (string) Str::uuid7(),
        'user_id' => $user->id,
        'title' => 'Older',
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    DB::table('agent_conversations')->insert([
        'id' => (string) Str::uuid7(),
        'user_id' => $user->id,
        'title' => 'Newer',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->getJson(route('conversations.index'));

    $data = $response->json();
    expect($data[0]['title'])->toBe('Newer');
    expect($data[1]['title'])->toBe('Older');
});

test('authenticated users can view a conversation', function () {
    $user = User::factory()->create();
    $convId = createConversation($user->id);
    createMessage($convId, $user->id, 'user', 'Hello');
    createMessage($convId, $user->id, 'assistant', 'Hi there!');

    $response = $this->actingAs($user)
        ->getJson(route('conversations.show', $convId));

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['id'])->toBe($convId);
    expect($data['messages'])->toHaveCount(2);
    expect($data['messages'][0]['role'])->toBe('user');
    expect($data['messages'][1]['role'])->toBe('assistant');
});

test('users cannot view other users conversations', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $convId = createConversation($other->id);

    $this->actingAs($user)
        ->getJson(route('conversations.show', $convId))
        ->assertNotFound();
});

test('authenticated users can delete a conversation', function () {
    $user = User::factory()->create();
    $convId = createConversation($user->id);
    createMessage($convId, $user->id, 'user', 'Hello');

    $response = $this->actingAs($user)
        ->deleteJson(route('conversations.destroy', $convId));

    $response->assertSuccessful();

    expect(DB::table('agent_conversations')->where('id', $convId)->exists())->toBeFalse();
    expect(DB::table('agent_conversation_messages')->where('conversation_id', $convId)->exists())->toBeFalse();
});

test('users cannot delete other users conversations', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $convId = createConversation($other->id);

    $this->actingAs($user)
        ->deleteJson(route('conversations.destroy', $convId))
        ->assertNotFound();

    expect(DB::table('agent_conversations')->where('id', $convId)->exists())->toBeTrue();
});

test('chat show route loads conversation messages', function () {
    $user = User::factory()->create();
    $convId = createConversation($user->id, 'My conversation');
    createMessage($convId, $user->id, 'user', 'What is RAG?');
    createMessage($convId, $user->id, 'assistant', 'RAG stands for Retrieval-Augmented Generation.');

    $response = $this->actingAs($user)
        ->get(route('chat.show', $convId));

    $response->assertOk();

    $props = $response->original->getData()['page']['props'];
    expect($props['conversationId'])->toBe($convId);
    expect($props['conversationTitle'])->toBe('My conversation');
    expect($props['initialMessages'])->toHaveCount(2);
    expect($props['initialMessages'][0]['role'])->toBe('user');
    expect($props['initialMessages'][0]['content'])->toBe('What is RAG?');
});

test('chat show route returns 404 for other users conversations', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $convId = createConversation($other->id);

    $this->actingAs($user)
        ->get(route('chat.show', $convId))
        ->assertNotFound();
});

test('chat index provides null conversation props', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('chat.index'));

    $response->assertOk();
    $props = $response->original->getData()['page']['props'];
    expect($props['conversationId'])->toBeNull();
    expect($props['initialMessages'])->toBeEmpty();
    expect($props['conversationTitle'])->toBeNull();
});
