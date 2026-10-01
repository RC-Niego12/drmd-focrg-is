<?php

use App\Models\AssistanceRequest;
use App\Models\DispatchDeliveryUpdate;
use App\Models\DispatchPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('lets QRT view delivery details and securely open evidence without edit access', function (): void {
    $permission = Permission::firstOrCreate(['name' => 'view dispatch delivery monitoring', 'guard_name' => 'web']);
    $role = Role::firstOrCreate(['name' => 'QRT', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);
    $qrt = User::create(['name' => 'QRT Viewer', 'email' => 'qrt-monitor@example.test', 'password' => 'password', 'is_active' => true, 'access_status' => 'approved']);
    $qrt->assignRole($role);
    $request = AssistanceRequest::create([
        'reference_number' => 'REQ-MONITOR-001', 'requesting_agency' => 'Test LGU', 'province' => 'Agusan del Norte',
        'municipality' => 'Butuan', 'requester' => 'Test Receiver', 'date_requested' => now()->toDateString(), 'status' => 'approved',
    ]);
    $dispatch = DispatchPlan::create([
        'dispatch_number' => 'DSP-MONITOR-001', 'request_id' => $request->id, 'destination' => 'Butuan City',
        'receiving_agency_lgu' => 'Test LGU', 'status' => DispatchPlan::STATUS_IN_TRANSIT,
        'vehicles_needed' => true, 'number_of_vehicles' => 2,
        'vehicle_details' => [
            ['vehicle_type' => 'Wing Van', 'vehicle_plate_number' => 'ABC-123', 'driver' => 'Driver One', 'escort_name' => 'Escort One'],
            ['vehicle_type' => 'Cargo Truck', 'vehicle_plate_number' => 'XYZ-789', 'driver' => 'Driver Two', 'escort_name' => 'Escort Two'],
        ],
        'local_handover_details' => ['source_warehouse_id' => 99, 'source_warehouse_name' => 'Test LGU Warehouse'],
    ]);
    Storage::disk('local')->put('dispatch-delivery-updates/test/evidence.jpg', 'evidence');
    $update = DispatchDeliveryUpdate::create([
        'dispatch_plan_id' => $dispatch->id, 'vehicle_index' => 0, 'reported_by' => $qrt->id, 'reporter_role' => 'DSWD Escort',
        'stage' => 'checkpoint', 'occurred_at' => now(), 'location' => 'Butuan City', 'latitude' => 8.9475, 'longitude' => 125.5406,
        'message' => 'Vehicle passed the recorded checkpoint.', 'photo_paths' => ['dispatch-delivery-updates/test/evidence.jpg'],
    ]);
    DispatchDeliveryUpdate::create([
        'dispatch_plan_id' => $dispatch->id, 'vehicle_index' => 1, 'reported_by' => $qrt->id, 'reporter_role' => 'DSWD Escort',
        'stage' => 'departed', 'occurred_at' => now()->addMinute(), 'location' => 'Cabadbaran City', 'latitude' => 9.1226, 'longitude' => 125.5355,
        'message' => 'The second vehicle departed with its assigned cargo.', 'photo_paths' => [],
    ]);

    $this->actingAs($qrt)->get(route('delivery-monitoring.index', ['dispatch_id' => $dispatch->id, 'update_id' => $update->id, 'stage' => 'checkpoint']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('DispatchMonitoring/Index')
            ->where('focusDispatchId', $dispatch->id)
            ->where('focusUpdateId', $update->id)
            ->where('focusStage', 'checkpoint')
            ->where('dispatches.0.operation_type', 'mixed')
            ->has('dispatches.0.vehicles', 2)
            ->where('dispatches.0.vehicles.1.plate', 'XYZ-789')
            ->has('dispatches.0.updates', 2)
            ->where('dispatches.0.updates.0.vehicle_index', 1)
            ->where('dispatches.0.updates.1.vehicle_index', 0)
            ->where('dispatches.0.updates.1.photos.0.label', 'Evidence photo 1'));

    $this->actingAs($qrt)->get(route('dispatches.delivery-updates.photos.show', [$dispatch, $update, 0]))->assertOk();
    $this->actingAs($qrt)->patch(route('dispatches.delivery-updates.message.update', [$dispatch, $update]), ['message' => 'Officials must not edit this update.'])->assertForbidden();
});

it('blocks users without monitoring access', function (): void {
    $user = User::create(['name' => 'Unrelated User', 'email' => 'unrelated-monitor@example.test', 'password' => 'password', 'is_active' => true, 'access_status' => 'approved']);
    $this->actingAs($user)->get(route('delivery-monitoring.index'))->assertForbidden();
});

it('recognizes canonical higher-authority roles as delivery monitoring viewers', function (string $roleName): void {
    $permission = Permission::firstOrCreate(['name' => 'view dispatch delivery monitoring', 'guard_name' => 'web']);
    $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $role->givePermissionTo($permission);
    $user = User::create([
        'name' => $roleName.' Viewer',
        'email' => Str::slug($roleName).'@authority.example.test',
        'password' => 'password',
        'is_active' => true,
        'access_status' => 'approved',
    ]);
    $user->assignRole($role);

    $this->actingAs($user)->get(route('delivery-monitoring.index'))->assertOk();
})->with(['RD', 'ARD', 'ARDO', 'Regional Director', 'Assistant Regional Director', 'Assistant Regional Director for Operations']);

it('does not invent a vehicle for a no-transport handover or expose it in the escort workspace', function (): void {
    $role = Role::firstOrCreate(['name' => 'RROS', 'guard_name' => 'web']);
    $user = User::create(['name' => 'RROS Monitor', 'email' => 'rros-local@example.test', 'password' => 'password', 'is_active' => true, 'access_status' => 'approved']);
    $user->assignRole($role);
    $request = AssistanceRequest::create([
        'reference_number' => 'REQ-LOCAL-001', 'requesting_agency' => 'Tubod LGU', 'province' => 'Surigao del Norte',
        'municipality' => 'Tubod', 'requester' => 'Local Receiver', 'date_requested' => now()->toDateString(), 'status' => 'approved',
    ]);
    $dispatch = DispatchPlan::create([
        'dispatch_number' => 'DSP-LOCAL-001', 'request_id' => $request->id, 'destination' => 'Tubod, Surigao del Norte',
        'receiving_agency_lgu' => 'Tubod LGU', 'status' => DispatchPlan::STATUS_RECEIVED,
        'vehicles_needed' => false, 'number_of_vehicles' => 0, 'vehicle_details' => [],
        'local_handover_details' => ['source_warehouse_name' => 'Tubod LGU Warehouse', 'released_at' => now(), 'received_at' => now()],
    ]);

    $this->actingAs($user)->get(route('delivery-monitoring.index', ['dispatch_id' => $dispatch->id]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('dispatches.0.transport_required', false)
            ->where('dispatches.0.operation_type', 'local_handover')
            ->has('dispatches.0.vehicles', 0));

    $this->actingAs($user)->get(route('delivery-escort.index', ['bucket' => 'completed', 'dispatch_id' => $dispatch->id]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('escortWorkspace', true)
            ->where('selectedDispatch', null)
            ->has('dispatches.data', 0));
});

it('passes the selected delivery vehicle into the escort workspace', function (): void {
    $role = Role::firstOrCreate(['name' => 'RROS', 'guard_name' => 'web']);
    $user = User::create(['name' => 'RROS Vehicle Monitor', 'email' => 'rros-vehicle-focus@example.test', 'password' => 'password', 'is_active' => true, 'access_status' => 'approved']);
    $user->assignRole($role);
    $request = AssistanceRequest::create([
        'reference_number' => 'REQ-FOCUS-001', 'requesting_agency' => 'Tubod LGU', 'province' => 'Surigao del Norte',
        'municipality' => 'Tubod', 'requester' => 'Test Receiver', 'date_requested' => now()->toDateString(), 'status' => 'approved',
    ]);
    $dispatch = DispatchPlan::create([
        'dispatch_number' => 'DSP-FOCUS-001', 'request_id' => $request->id, 'destination' => 'Tubod, Surigao del Norte',
        'receiving_agency_lgu' => 'Tubod LGU', 'status' => DispatchPlan::STATUS_IN_TRANSIT,
        'vehicles_needed' => true, 'number_of_vehicles' => 2,
        'vehicle_details' => [
            ['vehicle_type' => 'Van', 'vehicle_plate_number' => 'FIRST-01', 'driver' => 'Driver One', 'plan_confirmed_at' => now()],
            ['vehicle_type' => 'Closed Van', 'vehicle_plate_number' => 'SECOND-02', 'driver' => 'Driver Two', 'plan_confirmed_at' => now()],
        ],
    ]);

    $this->actingAs($user)->get(route('delivery-escort.index', [
        'bucket' => 'in_progress',
        'dispatch_id' => $dispatch->id,
        'focus_vehicle' => 1,
    ]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Requests/Dispatches')
        ->where('selectedDispatch.id', $dispatch->id)
        ->where('focusVehicleIndex', 1)
        ->where('selectedDispatch.vehicle_details.1.vehicle_plate_number', 'SECOND-02'));
});
