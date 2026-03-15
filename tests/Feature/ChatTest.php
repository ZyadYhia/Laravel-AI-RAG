<?php

use App\Models\Document;
use App\Models\User;

test('guests cannot access chat page', function () {
    $this->get(route('chat.index'))->assertRedirect(route('login'));
});

test('authenticated users can view chat page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('chat.index'))
        ->assertOk();
});

test('chat shows warning when no documents exist', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('chat.index'));

    $response->assertOk();
    $hasDocuments = $response->original->getData()['page']['props']['hasDocuments'];
    expect($hasDocuments)->toBeFalse();
});

test('chat shows documents available when they exist', function () {
    $user = User::factory()->create();
    Document::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->get(route('chat.index'));

    $response->assertOk();
    $hasDocuments = $response->original->getData()['page']['props']['hasDocuments'];
    expect($hasDocuments)->toBeTrue();
});

test('chat message requires content', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('chat.store'), ['message' => ''])
        ->assertUnprocessable();
});

test('chat message has max length', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('chat.store'), ['message' => str_repeat('a', 5001)])
        ->assertUnprocessable();
});
