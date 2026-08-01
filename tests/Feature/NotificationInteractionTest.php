<?php

use App\Models\User;
use App\Notifications\WorkflowNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('marks individual and all notifications read and returns safe internal destinations', function (): void {
    $user = User::create([
        'name' => 'Notification Reader',
        'email' => 'notification-reader@example.test',
        'password' => 'password',
        'is_active' => true,
        'access_status' => 'approved',
    ]);
    $user->notify(new WorkflowNotification([
        'title' => 'First notification',
        'message' => 'Open the dashboard.',
        'url' => route('dashboard'),
    ]));
    $user->notify(new WorkflowNotification([
        'title' => 'Second notification',
        'message' => 'Open the alert workspace.',
        'url' => route('regional-alert-acknowledgements.index'),
    ]));

    $payload = $this->actingAs($user)
        ->getJson(route('notifications.index'))
        ->assertOk()
        ->assertJsonPath('unread_count', 2)
        ->json();
    expect(collect($payload['notifications'])->pluck('url')->all())
        ->toContain('/', '/regional-alert-acknowledgements');

    $this->patchJson(route('notifications.read', $payload['notifications'][0]['id']))
        ->assertNoContent();
    expect($user->fresh()->unreadNotifications()->count())->toBe(1);

    $this->patchJson(route('notifications.read-all'))->assertNoContent();
    expect($user->fresh()->unreadNotifications()->count())->toBe(0);
});

it('does not expose an external notification destination to the browser', function (): void {
    $user = User::create([
        'name' => 'Safe Notification Reader',
        'email' => 'safe-notification-reader@example.test',
        'password' => 'password',
        'is_active' => true,
        'access_status' => 'approved',
    ]);
    $user->notify(new WorkflowNotification([
        'title' => 'Unsafe destination',
        'message' => 'This URL must not be followed.',
        'url' => 'https://untrusted.example/redirect',
    ]));

    $this->actingAs($user)
        ->getJson(route('notifications.index'))
        ->assertOk()
        ->assertJsonPath('notifications.0.url', null);
});
