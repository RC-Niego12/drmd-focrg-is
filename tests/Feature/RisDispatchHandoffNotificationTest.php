<?php

use App\Models\AssistanceRequest;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\WorkflowNotification;
use App\Services\InventoryBalanceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('notifies dispatch officers when a RIS is generated and is ready for signing', function (): void {

    $this->seed(DatabaseSeeder::class);

    Notification::fake();

    $rros = User::where('email', 'rros@example.test')->firstOrFail();

    $warehouse = Warehouse::create([

        'name' => 'RROS Hub',

        'province' => 'Agusan del Norte',

        'municipality' => 'Butuan City',

    ]);

    $balances = Mockery::mock(InventoryBalanceService::class);

    $balances->shouldReceive('balanceRows')->andReturn(collect([[

        'warehouse_id' => $warehouse->id,

        'item' => 'Family Food Pack',

        'available_balance' => 100,

    ]]));

    app()->instance(InventoryBalanceService::class, $balances);

    $request = AssistanceRequest::create([

        'reference_number' => 'REQ-RIS-DISPATCH-001',

        'requesting_agency' => 'Test LGU',

        'requester' => 'Representative',

        'date_requested' => now()->toDateString(),

        'purpose' => 'Relief Augmentation',

        'status' => 'acted',

        'assessment_status' => 'submitted',

        'endorsed_to_drrs' => true,

        'submission_type' => 'fni_request',

        'epirma_assessment_signed_at' => now(),

        'epirma_response_letter_signed_at' => now(),

    ]);

    $item = $request->items()->create([

        'item_name' => 'Family Food Pack',

        'requested_quantity' => 10,

        'approved_quantity' => 8,

        'unit' => 'boxes',

        'status' => 'approved',

    ]);

    $prefix = 'CARAGA-FO-DRMD-RROS-A-REQ-'.now()->format('y-m').'-';

    $this->actingAs($rros)

        ->post("/rros/requests/{$request->id}/ris", [

            'ris_number' => 'RIS-DISPATCH-0001',

            'ris_date' => now()->toDateString(),

            'purpose_of_release' => 'Relief augmentation',

            'recipient' => 'Test LGU',

            'delivery_site' => 'Butuan City',

            'status' => 'prepared',

            'tracking_data' => [

                'assessment_drn_for_ris' => 'ASSESS-DRN-DISPATCH',

                'purpose_of_request' => 'Relief Augmentation',

                'incident_type' => 'Fire Incident',

                'incident_specification' => 'Test fire',

                'dr_number' => 'DR#-08-9001',

                'ris_drn' => $prefix.'9001',

            ],

            'items' => [[

                'request_item_id' => $item->id,

                'item_name' => $item->item_name,

                'unit' => $item->unit,

                'warehouse_id' => $warehouse->id,

                'warehouse_name' => $warehouse->name,

                'quantity' => 8,

            ]],

        ])

        ->assertRedirect()

        ->assertSessionHas(

            'success',

            'RIS / DR generated successfully. Please notify the dispatch officer that an RIS is ready for signing.'

        );

    Notification::assertSentTo(

        $rros,

        WorkflowNotification::class,

        fn (WorkflowNotification $notification): bool => ($notification->toArray($rros)['action_key'] ?? null) === 'ris_ready_for_signing'

            && str_contains((string) ($notification->toArray($rros)['message'] ?? ''), 'ready for signing')

            && ! str_contains((string) ($notification->toArray($rros)['message'] ?? ''), 'Dispatch Plan')

            && ! str_contains((string) ($notification->toArray($rros)['url'] ?? ''), '/dispatches')

    );

});
