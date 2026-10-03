<?php

use App\Models\DistributionPlan;
use App\Models\LguDirectoryEntry;
use App\Models\OperationalLibraryValue;
use App\Models\RequestParty;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseSheetImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('rejects a sotex allocation that is not backed by near-expiry stock', function (): void {
    $role = Role::firstOrCreate(['name' => 'DRRS', 'guard_name' => 'web']);
    $user = User::create([
        'name' => 'DRRS Planner',
        'email' => 'drrs-sotex@example.test',
        'password' => 'password',
        'is_active' => true,
        'access_status' => 'approved',
    ]);
    $user->assignRole($role);
    $warehouse = Warehouse::create([
        'name' => 'Dapa01',
        'province' => 'Agusan Del Norte',
        'municipality' => 'Dapa',
        'district' => 'ADN1',
        'partnership' => 'DSWD',
        'status' => 'active',
    ]);

    $this->actingAs($user)->post('/near-expiry/plans', [
        'lgu' => 'Dapa, SUN',
        'activity' => 'Food for Work',
        'item_name' => 'Family Food Pack',
        'brand' => 'Prepacked',
        'expiry_month' => 'Sep 2026',
        'warehouse_id' => $warehouse->id,
        'allocated_quantity' => 300,
        'released_quantity' => 0,
        'compliance_target_na' => true,
        'delivery_target_na' => true,
        'delivery_mode_na' => true,
    ])->assertSessionHasErrors('allocated_quantity');
});

it('shows the sotex planner on the rros near-expiry page', function (): void {
    $role = Role::firstOrCreate(['name' => 'DRRS', 'guard_name' => 'web']);
    $user = User::create([
        'name' => 'DRRS Planner View',
        'email' => 'drrs-sotex-view@example.test',
        'password' => 'password',
        'is_active' => true,
        'access_status' => 'approved',
    ]);
    $user->assignRole($role);

    $this->actingAs($user)->get('/near-expiry')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/NearExpiry')
            ->where('workspace', 'rros')
            ->where('canManageSotex', true)
            ->has('sotex.activities')
            ->has('sotex.stock')
            ->has('sotex.plans'));
});

it('keeps sotex proposals view-only for every role except drrs', function (): void {
    $dapa = warehouse('Dapa01', 'Dapa');
    stockLine($dapa, 2, 'Family Food Pack', '800');
    $drrs = planner();
    $this->actingAs($drrs)->post('/near-expiry/plans', proposalPayload([source($dapa, '100')]))->assertSessionHasNoErrors();
    $plan = DistributionPlan::query()->firstOrFail();

    $permission = Permission::firstOrCreate(['name' => 'manage near expiry', 'guard_name' => 'web']);
    foreach (['RROS', 'RROS AA', 'Super Admin'] as $roleName) {
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        $viewer = User::create([
            'name' => $roleName.' Viewer',
            'email' => 'sotex-viewer-'.uniqid().'@example.test',
            'password' => 'password',
            'is_active' => true,
            'access_status' => 'approved',
        ]);
        $viewer->assignRole($role);

        $this->actingAs($viewer)->get('/near-expiry')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('canManageSotex', false)->has('sotex.plans', 1));

        $this->actingAs($viewer)->post('/near-expiry/plans', proposalPayload([source($dapa, '50')]))->assertForbidden();
        $this->actingAs($viewer)->patch('/near-expiry/plans/'.$plan->id, proposalPayload([source($dapa, '50')]))->assertForbidden();
        $this->actingAs($viewer)->delete('/near-expiry/plans/'.$plan->id)->assertForbidden();
        $this->actingAs($viewer)->postJson('/near-expiry/recipients', ['name' => 'Philippine Red Cross'])->assertForbidden();
        $this->actingAs($viewer)->getJson('/near-expiry/recipients')->assertForbidden();
    }

    expect(DistributionPlan::query()->count())->toBe(1)
        ->and((float) $plan->fresh()->allocated_quantity)->toBe(100.0);
});

it('keeps a custom recipient in the sotex recipient library', function (): void {
    $user = planner();

    $this->actingAs($user)->post('/near-expiry/recipients', [
        'name' => 'Barangay Hall, Test',
    ])->assertRedirect();

    $this->assertDatabaseHas('operational_library_values', [
        'library_type' => 'sotex_recipient',
        'value' => 'Barangay Hall, Test',
        'is_active' => 1,
    ]);

    $this->actingAs($user)->post('/near-expiry/recipients', [
        'name' => 'barangay hall, test',
    ])->assertRedirect();

    expect(OperationalLibraryValue::query()->where('library_type', 'sotex_recipient')->count())->toBe(1)
        ->and(RequestParty::query()->count())->toBe(0);
});

it('adds a recipient in the background and returns the refreshed list', function (): void {
    $user = planner();
    LguDirectoryEntry::create(['source_sheet' => 'SDN', 'lgu_name' => 'DAPA', 'lgu_level' => 'MLGU', 'is_active' => true]);

    $this->actingAs($user)->postJson('/near-expiry/recipients', ['name' => 'Philippine Red Cross', 'office' => 'Surigao Chapter'])
        ->assertCreated()
        ->assertJsonPath('recipient', ['name' => 'Philippine Red Cross', 'details' => 'Surigao Chapter', 'level' => ''])
        ->assertJsonFragment(['name' => 'MLGU - Dapa, SDN'])
        ->assertJsonFragment(['name' => 'Philippine Red Cross']);

    $this->actingAs($user)->postJson('/near-expiry/recipients', ['name' => 'Dapa'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    $this->actingAs($user)->getJson('/near-expiry/recipients')
        ->assertOk()
        ->assertJsonCount(2, 'recipients');
});

it('pushes a socket event to planners when a recipient is added', function (): void {
    config([
        'realtime.enabled' => true,
        'realtime.public_url' => 'https://example.test:6001',
        'realtime.internal_url' => 'http://127.0.0.1:6002',
        'realtime.secret' => 'test-secret',
    ]);
    Http::fake();
    $user = planner();

    $this->actingAs($user)->postJson('/near-expiry/recipients', ['name' => 'Philippine Coast Guard Auxiliary'])->assertCreated();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/publish')
        && $request['event'] === 'sotex.recipients.changed'
        && in_array('user:'.$user->id, $request['rooms'], true));
});

it('lets drrs manage the sotex recipient library with office details', function (): void {
    $user = planner();
    LguDirectoryEntry::create(['source_sheet' => 'SDN', 'lgu_name' => 'DAPA', 'lgu_level' => 'MLGU', 'is_active' => true]);

    $this->actingAs($user)->get('/libraries')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('operationalLibraryTypes.sotex_recipient'));

    $this->actingAs($user)->post('/operational-library', [
        'library_type' => 'sotex_recipient',
        'value' => 'Philippine Red Cross',
        'office' => 'Surigao Chapter',
        'context' => 'all',
        'is_active' => true,
    ])->assertSessionHasNoErrors();

    $this->actingAs($user)->post('/near-expiry/recipients', [
        'name' => 'Office of Civil Defense',
        'office' => 'OCD Caraga',
    ])->assertSessionHasNoErrors();

    $this->actingAs($user)->post('/operational-library', [
        'library_type' => 'sotex_recipient',
        'value' => 'MLGU - Dapa, SDN',
        'context' => 'all',
        'is_active' => true,
    ])->assertSessionHasErrors('value');

    expect(OperationalLibraryValue::query()->where('library_type', 'sotex_recipient')->orderBy('value')->get(['value', 'metadata'])->map(fn ($row) => [$row->value, $row->metadata['office'] ?? null])->all())
        ->toBe([['Office of Civil Defense', 'OCD Caraga'], ['Philippine Red Cross', 'Surigao Chapter']]);
});

it('lists lgus from the lgu directory and keeps them out of the recipient library', function (): void {
    $user = planner();
    LguDirectoryEntry::create(['source_sheet' => 'SDN', 'lgu_name' => 'DAPA', 'lgu_level' => 'MLGU', 'congressional_district' => '1st District', 'is_active' => true]);
    LguDirectoryEntry::create(['source_sheet' => 'SDN', 'lgu_name' => 'Surigao del Norte', 'lgu_level' => 'PLGU', 'is_active' => true]);
    LguDirectoryEntry::create(['source_sheet' => 'ADN', 'lgu_name' => 'CARMEN', 'lgu_level' => 'MLGU', 'congressional_district' => '2nd District', 'is_active' => true]);
    RequestParty::create(['directory_key' => 'a', 'requesting_party' => 'Armed Forces of the Philippines', 'office_agency_details' => 'NGA', 'is_active' => true]);
    RequestParty::create(['directory_key' => 'b', 'requesting_party' => 'MLGU - Dapa, SDN', 'lgu_level' => 'MLGU', 'is_active' => true]);

    $this->actingAs($user)->get('/near-expiry')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sotex.recipients', [
                ['name' => 'MLGU - Carmen, ADN', 'details' => 'Agusan del Norte · 2nd District', 'level' => 'MLGU'],
                ['name' => 'PLGU - Surigao del Norte', 'details' => 'Surigao del Norte', 'level' => 'PLGU'],
                ['name' => 'MLGU - Dapa, SDN', 'details' => 'Surigao del Norte · 1st District', 'level' => 'MLGU'],
                ['name' => 'Armed Forces of the Philippines', 'details' => 'NGA', 'level' => ''],
            ]));

    $this->actingAs($user)->post('/near-expiry/recipients', ['name' => 'Dapa'])
        ->assertSessionHasErrors('name');

    expect(OperationalLibraryValue::query()->where('library_type', 'sotex_recipient')->pluck('value')->all())
        ->toBe(['Armed Forces of the Philippines']);
});

it('saves one proposal that draws from more than one warehouse', function (): void {
    $user = planner();
    $dapa = warehouse('Dapa01', 'Dapa');
    $butuan = warehouse('Tiniwisan', 'Butuan City');
    stockLine($dapa, 2, 'Family Food Pack', '800');
    stockLine($butuan, 3, 'Family Food Pack', '500');

    $this->actingAs($user)->post('/near-expiry/plans', proposalPayload([
        source($dapa, '100'),
        source($butuan, '80'),
    ]))->assertRedirect()->assertSessionHasNoErrors();

    $plans = DistributionPlan::query()->get();
    expect($plans)->toHaveCount(2)
        ->and($plans->pluck('proposal_key')->unique())->toHaveCount(1)
        ->and($plans->pluck('warehouse_id')->sort()->values()->all())->toBe([$dapa->id, $butuan->id])
        ->and($plans->every(fn (DistributionPlan $plan): bool => $plan->lgu === 'MLGU - Dapa, SDN' && $plan->activity === 'Food-for-Work'))->toBeTrue();

    $this->actingAs($user)->delete('/near-expiry/plans/'.$plans->first()->id)->assertRedirect();
    expect(DistributionPlan::query()->count())->toBe(0);
});

it('keeps the proposal unsaved when one of its warehouse lines is over-allocated', function (): void {
    $user = planner();
    $dapa = warehouse('Dapa01', 'Dapa');
    $butuan = warehouse('Tiniwisan', 'Butuan City');
    stockLine($dapa, 2, 'Family Food Pack', '800');
    stockLine($butuan, 3, 'Family Food Pack', '40');

    $this->actingAs($user)->post('/near-expiry/plans', proposalPayload([
        source($dapa, '100'),
        source($butuan, '80'),
    ]))->assertSessionHasErrors('allocated_quantity');

    expect(DistributionPlan::query()->count())->toBe(0);
});

it('keeps actual stage dates off a new proposal until it is updated', function (): void {
    $user = planner();
    $dapa = warehouse('Dapa01', 'Dapa');
    stockLine($dapa, 2, 'Family Food Pack', '800');

    $this->actingAs($user)->post('/near-expiry/plans', [
        ...proposalPayload([array_merge(source($dapa, '100'), ['released_quantity' => 40])]),
        'proposal_target_on' => '2026-05-01',
        'proposal_received_on' => '2026-05-02',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $plan = DistributionPlan::query()->first();
    expect($plan->proposal_target_on->toDateString())->toBe('2026-05-01')
        ->and($plan->proposal_received_on)->toBeNull()
        ->and($plan->released_quantity)->toBe('0.00');

    $this->actingAs($user)->patch('/near-expiry/plans/'.$plan->id, [
        ...proposalPayload([source($dapa, '100')]),
        'proposal_target_on' => '2026-05-01',
        'proposal_received_on' => '2026-05-08',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($plan->fresh()->proposal_received_on->toDateString())->toBe('2026-05-08');
});

it('only records a release once every applicable actual date is filled', function (): void {
    $user = planner();
    $dapa = warehouse('Dapa01', 'Dapa');
    stockLine($dapa, 2, 'Family Food Pack', '800');

    $this->actingAs($user)->post('/near-expiry/plans', proposalPayload([source($dapa, '100')]))
        ->assertRedirect()->assertSessionHasNoErrors();
    $plan = DistributionPlan::query()->first();

    $actualDates = [
        'proposal_received_on' => '2026-05-02',
        'proposal_reviewed_on' => '2026-05-03',
        'proposal_approved_on' => '2026-05-05',
        'work_schedule_on' => '2026-05-06',
        'work_schedule_end_on' => '2026-05-07',
        'distributed_on' => '2026-05-09',
        'distributed_end_on' => '2026-05-10',
    ];

    $this->actingAs($user)->patch('/near-expiry/plans/'.$plan->id, [
        ...proposalPayload([array_merge(source($dapa, '100'), ['released_quantity' => 25])]),
        ...array_merge($actualDates, ['distributed_end_on' => null]),
    ])->assertSessionHasErrors('released_quantity');

    expect($plan->fresh()->released_quantity)->toBe('0.00');

    $this->actingAs($user)->patch('/near-expiry/plans/'.$plan->id, [
        ...proposalPayload([array_merge(source($dapa, '100'), ['released_quantity' => 25])]),
        ...$actualDates,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($plan->fresh()->released_quantity)->toBe('25.00');
});

it('sets current progress from the dates instead of the submitted value', function (): void {
    $user = planner();
    $dapa = warehouse('Dapa01', 'Dapa');
    stockLine($dapa, 2, 'Family Food Pack', '800');

    $this->actingAs($user)->post('/near-expiry/plans', [
        ...proposalPayload([source($dapa, '100')]),
        'progress_status' => 'SoTEx Fully Distributed',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $plan = DistributionPlan::query()->first();
    expect($plan->progress_status)->toBe("Awaiting LGU's Submission");

    $this->actingAs($user)->patch('/near-expiry/plans/'.$plan->id, [
        ...proposalPayload([source($dapa, '100')]),
        'proposal_received_on' => '2026-05-02',
        'proposal_reviewed_on' => '2026-05-03',
        'proposal_approved_on' => '2026-05-05',
        'work_schedule_on' => '2026-05-06',
        'progress_status' => 'SoTEx Fully Distributed',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($plan->fresh()->progress_status)->toBe('Ongoing Activity');
});

it('lets the activity run during revisions but keeps proposal steps and goods movement in order', function (): void {
    $user = planner();
    $dapa = warehouse('Dapa01', 'Dapa');
    stockLine($dapa, 2, 'Family Food Pack', '800');

    $this->actingAs($user)->post('/near-expiry/plans', proposalPayload([source($dapa, '100')]))->assertSessionHasNoErrors();
    $plan = DistributionPlan::query()->first();
    $underRevision = [
        ...proposalPayload([source($dapa, '100')]),
        'compliance_target_na' => false,
        'compliance_target_on' => '2026-05-10',
        'delivery_target_na' => false,
        'delivery_target_on' => '2026-05-20',
        'delivery_target_end_on' => '2026-05-22',
        'proposal_received_on' => '2026-05-02',
        'proposal_reviewed_on' => '2026-05-04',
        'work_schedule_on' => '2026-05-06',
        'work_schedule_end_on' => '2026-05-09',
    ];
    $patch = fn (array $changes) => $this->actingAs($user)->patch('/near-expiry/plans/'.$plan->id, [...$underRevision, ...$changes]);

    $patch([])->assertRedirect()->assertSessionHasNoErrors();
    expect($plan->fresh()->work_schedule_end_on->toDateString())->toBe('2026-05-09');

    $patch(['proposal_received_on' => null])->assertSessionHasErrors(['proposal_reviewed_on' => 'Record the actual receipt of proposal first.']);
    $patch(['proposal_reviewed_on' => '2026-05-01'])->assertSessionHasErrors(['proposal_reviewed_on' => 'This cannot be earlier than the actual receipt of proposal (May 2, 2026).']);
    $patch(['proposal_approved_on' => '2026-05-12'])->assertSessionHasErrors(['proposal_approved_on' => 'Record the actual LGU compliance on findings first.']);
    $patch(['delivered_on' => '2026-05-20'])->assertSessionHasErrors(['delivered_on' => 'Record the actual approval of proposal first.']);

    $approved = ['compliance_on' => '2026-05-08', 'proposal_approved_on' => '2026-05-12'];
    $patch([...$approved, 'delivered_on' => '2026-05-11'])->assertSessionHasErrors(['delivered_on' => 'This cannot be earlier than the actual approval of proposal (May 12, 2026).']);
    $patch([...$approved, 'distributed_on' => '2026-05-21'])->assertSessionHasErrors(['distributed_on' => 'Record the actual delivery start first.']);
    $patch([...$approved, 'delivered_on' => '2026-05-20', 'distributed_on' => '2026-05-21', 'distributed_end_on' => '2026-05-23'])
        ->assertSessionHasErrors(['distributed_end_on' => 'Record the actual delivery end first.']);
    $patch([...$approved, 'delivered_on' => '2026-05-20', 'delivered_end_on' => '2026-05-24', 'distributed_on' => '2026-05-21', 'distributed_end_on' => '2026-05-23'])
        ->assertSessionHasErrors(['distributed_end_on' => 'This cannot be earlier than the actual delivery end (May 24, 2026).']);

    $patch([...$approved, 'delivered_on' => '2026-05-20', 'distributed_on' => '2026-05-21'])->assertRedirect()->assertSessionHasNoErrors();
    expect($plan->fresh()->progress_status)->toBe('SoTEx Partially Distributed');

    $patch([...$approved, 'delivery_target_na' => true, 'distributed_on' => '2026-05-13', 'distributed_end_on' => '2026-05-14'])->assertRedirect()->assertSessionHasNoErrors();
    expect($plan->fresh()->distributed_end_on->toDateString())->toBe('2026-05-14');
});

it('stores a date range for work, delivery, and distribution targets', function (): void {
    $user = planner();
    $dapa = warehouse('Dapa01', 'Dapa');
    stockLine($dapa, 4, 'Family Food Pack', '800');

    $this->actingAs($user)->post('/near-expiry/plans', [
        ...proposalPayload([source($dapa, '100')]),
        'proposal_target_on' => '2026-05-01',
        'work_schedule_target_on' => '2026-06-01',
        'work_schedule_target_end_on' => '2026-06-05',
        'delivery_target_on' => '2026-06-08',
        'delivery_target_end_on' => '2026-06-10',
        'distribution_target_on' => '2026-06-12',
        'distribution_target_end_on' => '2026-06-15',
        'delivery_target_na' => false,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $plan = DistributionPlan::query()->first();
    expect($plan->proposal_target_on->toDateString())->toBe('2026-05-01')
        ->and($plan->work_schedule_target_on->toDateString())->toBe('2026-06-01')
        ->and($plan->work_schedule_target_end_on->toDateString())->toBe('2026-06-05')
        ->and($plan->delivery_target_on->toDateString())->toBe('2026-06-08')
        ->and($plan->delivery_target_end_on->toDateString())->toBe('2026-06-10')
        ->and($plan->distribution_target_on->toDateString())->toBe('2026-06-12')
        ->and($plan->distribution_target_end_on->toDateString())->toBe('2026-06-15');

    $this->actingAs($user)->post('/near-expiry/plans', [
        ...proposalPayload([source($dapa, '50')]),
        'delivery_target_on' => '2026-06-10',
        'delivery_target_end_on' => '2026-06-01',
        'delivery_target_na' => false,
    ])->assertSessionHasErrors('delivery_target_end_on');

    expect(DistributionPlan::query()->count())->toBe(1);
});

it('records actual work, delivery, and distribution dates as ranges when updating', function (): void {
    $user = planner();
    $dapa = warehouse('Dapa01', 'Dapa');
    stockLine($dapa, 4, 'Family Food Pack', '800');

    $this->actingAs($user)->post('/near-expiry/plans', [
        ...proposalPayload([source($dapa, '100')]),
        'work_schedule_on' => '2026-06-01',
        'work_schedule_end_on' => '2026-06-03',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $plan = DistributionPlan::query()->first();
    expect($plan->work_schedule_on)->toBeNull()
        ->and($plan->work_schedule_end_on)->toBeNull();

    $this->actingAs($user)->patch('/near-expiry/plans/'.$plan->id, [
        ...proposalPayload([source($dapa, '100')]),
        'delivery_target_na' => false,
        'delivery_target_on' => '2026-06-05',
        'delivery_target_end_on' => '2026-06-07',
        'proposal_received_on' => '2026-05-28',
        'proposal_reviewed_on' => '2026-05-29',
        'proposal_approved_on' => '2026-05-31',
        'work_schedule_on' => '2026-06-01',
        'work_schedule_end_on' => '2026-06-03',
        'delivered_on' => '2026-06-08',
        'delivered_end_on' => '2026-06-09',
        'distributed_on' => '2026-06-12',
        'distributed_end_on' => '2026-06-14',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $updated = $plan->fresh();
    expect($updated->work_schedule_end_on->toDateString())->toBe('2026-06-03')
        ->and($updated->delivered_end_on->toDateString())->toBe('2026-06-09')
        ->and($updated->distributed_on->toDateString())->toBe('2026-06-12')
        ->and($updated->distributed_end_on->toDateString())->toBe('2026-06-14');

    $this->actingAs($user)->patch('/near-expiry/plans/'.$plan->id, [
        ...proposalPayload([source($dapa, '100')]),
        'distributed_on' => '2026-06-09',
        'distributed_end_on' => '2026-06-02',
    ])->assertSessionHasErrors('distributed_end_on');
});

it('stores not applicable on compliance, delivery dates, and who delivers', function (): void {
    $user = planner();
    $dapa = warehouse('Dapa01', 'Dapa');
    stockLine($dapa, 5, 'Family Food Pack', '800');

    $this->actingAs($user)->post('/near-expiry/plans', [
        'lgu' => 'MLGU - Dapa, SDN',
        'activity' => 'Food-for-Work',
        'sources' => [source($dapa, '100')],
    ])->assertSessionHasErrors([
        'compliance_target_on',
        'delivery_target_on',
        'delivery_target_end_on',
        'delivery_mode',
    ]);

    expect(DistributionPlan::query()->count())->toBe(0);

    $this->actingAs($user)->post('/near-expiry/plans', [
        ...proposalPayload([source($dapa, '100')]),
        'compliance_target_on' => '2026-06-01',
        'delivery_target_on' => '2026-06-08',
        'delivery_target_end_on' => '2026-06-10',
        'delivery_mode' => 'c/o LGU',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $plan = DistributionPlan::query()->first();
    expect($plan->compliance_target_na)->toBeTrue()
        ->and($plan->compliance_target_on)->toBeNull()
        ->and($plan->delivery_target_na)->toBeTrue()
        ->and($plan->delivery_target_on)->toBeNull()
        ->and($plan->delivery_target_end_on)->toBeNull()
        ->and($plan->delivery_mode_na)->toBeTrue()
        ->and($plan->delivery_mode)->toBeNull();

    $this->actingAs($user)->patch('/near-expiry/plans/'.$plan->id, [
        ...proposalPayload([source($dapa, '100')]),
        'proposal_received_on' => '2026-06-01',
        'proposal_reviewed_on' => '2026-06-02',
        'proposal_approved_on' => '2026-06-05',
        'compliance_on' => '2026-06-02',
        'delivered_on' => '2026-06-08',
        'delivered_end_on' => '2026-06-09',
        'distributed_on' => '2026-06-12',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $updated = $plan->fresh();
    expect($updated->compliance_on)->toBeNull()
        ->and($updated->delivered_on)->toBeNull()
        ->and($updated->delivered_end_on)->toBeNull()
        ->and($updated->distributed_on->toDateString())->toBe('2026-06-12');
});

function planner(): User
{
    $role = Role::firstOrCreate(['name' => 'DRRS', 'guard_name' => 'web']);
    $user = User::create([
        'name' => 'DRRS Planner Multi',
        'email' => 'drrs-sotex-multi-'.uniqid().'@example.test',
        'password' => 'password',
        'is_active' => true,
        'access_status' => 'approved',
    ]);
    $user->assignRole($role);

    return $user;
}

function warehouse(string $name, string $municipality): Warehouse
{
    return Warehouse::create([
        'name' => $name,
        'province' => 'Agusan Del Norte',
        'municipality' => $municipality,
        'district' => 'ADN1',
        'partnership' => 'DSWD',
        'status' => 'active',
    ]);
}

function stockLine(Warehouse $warehouse, int $row, string $item, string $receipt): void
{
    WarehouseSheetImport::create([
        'sheet_id' => 'sotex-test',
        'gid' => '1',
        'sheet_row_number' => $row,
        'row_hash' => 'row-'.$row,
        'warehouse_id' => $warehouse->id,
        'import_status' => 'imported',
        'raw_payload' => [
            'item_category' => 'Food Items',
            'item' => $item,
            'brand_description' => 'Prepacked',
            'receipt_expiry' => 'Sep 2026',
            'receipt' => $receipt,
            'receipt_cost' => '100',
            'receipt_unit_cost' => '1',
        ],
    ]);
}

function source(Warehouse $warehouse, string $allocated): array
{
    return [
        'warehouse_id' => $warehouse->id,
        'item_name' => 'Family Food Pack',
        'brand' => 'Prepacked',
        'expiry_month' => 'Sep 2026',
        'allocated_quantity' => $allocated,
        'released_quantity' => 0,
    ];
}

function proposalPayload(array $sources): array
{
    return [
        'lgu' => 'MLGU - Dapa, SDN',
        'activity' => 'Food-for-Work',
        'sources' => $sources,
        'compliance_target_na' => true,
        'delivery_target_na' => true,
        'delivery_mode_na' => true,
    ];
}
