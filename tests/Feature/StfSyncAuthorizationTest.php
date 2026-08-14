<?php

use App\Models\User;
use App\Services\StfSheetSyncService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns a JSON result when an RROS user synchronizes STF tracking data', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'rros@example.test')->firstOrFail();
    $sync = Mockery::mock(StfSheetSyncService::class);
    $sync->shouldReceive('run')->once()->with('manual', $user->id)->andReturn([
        'rows_seen' => 4,
        'records_created' => 0,
        'records_updated' => 4,
        'items_synced' => 4,
        'source' => 'operational',
    ]);
    app()->instance(StfSheetSyncService::class, $sync);

    $this->actingAs($user)
        ->postJson('/rros/stf/sync')
        ->assertOk()
        ->assertJsonPath('message', 'STF sync completed (operational): 4 row(s) reviewed, 4 tracking row(s) refreshed.');
});

it('lets RROS open the STF tracking endpoint', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'rros@example.test')->firstOrFail();
    $sync = Mockery::mock(StfSheetSyncService::class);
    $sync->shouldReceive('trackingRows')->once()->andReturn(collect([
        [
            'id' => 1,
            'stf_reference' => 'STF-001',
            'transaction_date' => now()->toDateString(),
            'type' => 'release',
            'purpose' => 'Relief',
            'item' => 'Family Food Pack',
            'unit' => 'boxes',
            'quantity' => 10,
            'warehouse' => 'Hub',
            'recipient' => 'LGU',
            'delivery_site' => 'Butuan',
            'encoded_by' => 'RROS',
            'synced_at' => now()->toIso8601String(),
        ],
    ]));
    app()->instance(StfSheetSyncService::class, $sync);

    $this->actingAs($user)
        ->getJson('/rros/stf/tracking')
        ->assertOk()
        ->assertJsonPath('data.0.stf_reference', 'STF-001');
});
