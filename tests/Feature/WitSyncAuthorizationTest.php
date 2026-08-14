<?php

use App\Models\User;
use App\Services\WitSyncService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows an RROS user to run the WIT synchronization', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'rros@example.test')->firstOrFail();
    $sync = Mockery::mock(WitSyncService::class);
    $sync->shouldReceive('run')->once()->with('manual', $user->id)->andReturn(['changed' => false]);
    app()->instance(WitSyncService::class, $sync);

    $this->actingAs($user)
        ->post('/wit/sync')
        ->assertRedirect()
        ->assertSessionHas('success', 'WIT sync completed; no data changes were found.');
});

it('allows an RROS user to view WIT synchronization history', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'rros@example.test')->firstOrFail();
    $sync = Mockery::mock(WitSyncService::class);
    $sync->shouldReceive('history')->once()->andReturn(collect());
    app()->instance(WitSyncService::class, $sync);

    $this->actingAs($user)
        ->getJson('/wit/sync-history')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});
