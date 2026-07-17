<?php

use App\Models\SystemMessage;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lets approved users exchange read tracked system messages', function (): void {
    $this->seed(DatabaseSeeder::class);
    $sender = User::where('email', 'drmd-aa@example.test')->firstOrFail();
    $recipient = User::where('email', 'drrs@example.test')->firstOrFail();

    $this->actingAs($sender)
        ->postJson(route('messages.store'), [
            'recipient_id' => $recipient->id,
            'subject' => 'Please review request',
            'body' => 'Kindly check the routed DROMIC request.',
        ])
        ->assertCreated()
        ->assertJsonPath('item.subject', 'Please review request')
        ->assertJsonPath('item.is_mine', true);

    $message = SystemMessage::firstOrFail();

    $this->actingAs($recipient)
        ->getJson(route('messages.index'))
        ->assertOk()
        ->assertJsonPath('unread_count', 1)
        ->assertJsonPath('messages.0.is_mine', false);

    $this->actingAs($recipient)
        ->patchJson(route('messages.read', $message))
        ->assertOk();

    expect($message->fresh()->read_at)->not->toBeNull();
});

it('prevents sending messages to yourself or unapproved users', function (): void {
    $this->seed(DatabaseSeeder::class);
    $sender = User::where('email', 'drmd-aa@example.test')->firstOrFail();
    $pending = User::create([
        'name' => 'Pending Person',
        'email' => 'pending-message@example.test',
        'password' => 'password',
        'office' => 'DRMD',
        'is_active' => true,
        'access_status' => 'pending',
    ]);

    $this->actingAs($sender)
        ->postJson(route('messages.store'), [
            'recipient_id' => $sender->id,
            'body' => 'This should not work.',
        ])
        ->assertUnprocessable();

    $this->actingAs($sender)
        ->postJson(route('messages.store'), [
            'recipient_id' => $pending->id,
            'body' => 'This should not work either.',
        ])
        ->assertUnprocessable();
});
