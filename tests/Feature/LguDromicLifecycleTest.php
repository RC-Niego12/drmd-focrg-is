<?php

use App\Http\Controllers\LguDromicRequestController;
use App\Models\AssistanceRequest;
use App\Models\FniLibraryItem;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

function completeLguDromicPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'submission_status' => 'final',
        'requesting_lgu' => 'Municipality of Test',
        'requester_name' => 'Test DROMIC Reporter',
        'incident_name' => 'Test Fire Incident',
        'incident_date' => '2026-07-27',
        'occurrence_started_at' => '2026-07-27T08:00',
        'information_received_at' => '2026-07-27T09:00',
        'incident_ended_at' => '2026-07-27T10:00',
        'province' => 'Agusan del Norte',
        'municipality' => 'Municipality of Test',
        'has_relief_request' => false,
        'narrative' => 'A fire affected the locality. The LGU validated the affected population and continues response operations.',
        'incident_type' => 'Fire Incident',
        'affected_barangays' => ['Test Barangay'],
        'incident_status' => 'Ongoing',
        'area_rows' => [[
            'area' => 'Test Barangay',
            'affected_families' => 5,
            'affected_persons' => 20,
        ]],
        'response_action_rows' => [[
            'acted_by_office' => 'LDRRMC',
            'action_intervention' => 'The LGU conducted validation and provided immediate assistance.',
        ]],
        'not_applicable_sections' => [
            'inside_ec',
            'outside_ec',
            'damaged_houses',
            'assistance',
            'related_incidents',
            'casualties',
            'infrastructure_damage',
            'agriculture_damage',
            'class_suspension',
            'work_suspension',
            'roads_bridges',
            'power_lifelines',
            'water_lifelines',
            'communication_lifelines',
            'seaports',
            'airports',
            'land_transport_terminals',
            'stranded_transport',
            'calamity_declaration',
            'preemptive_evacuation',
            'cluster_gaps',
            'photo_documentation',
        ],
    ], $overrides);
}

it('creates one signed relief request for multiple incidents of the same type', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();
    $fniItem = FniLibraryItem::query()->create([
        'item_category' => 'Food',
        'item_name' => 'Family Food Pack',
        'brand_description' => 'Standard family food pack',
        'unit_of_measure' => 'box',
    ]);

    foreach ([
        ['incident_name' => 'Purok One Fire', 'incident_date' => '2026-08-01', 'occurrence_started_at' => '2026-08-01T08:00', 'information_received_at' => '2026-08-01T09:00', 'incident_ended_at' => '2026-08-01T10:00'],
        ['incident_name' => 'Purok Two Fire', 'incident_date' => '2026-08-02', 'occurrence_started_at' => '2026-08-02T09:00', 'information_received_at' => '2026-08-02T10:00', 'incident_ended_at' => '2026-08-02T11:00'],
    ] as $incident) {
        $this->actingAs($user)
            ->post('/lgu/dromic-sitrep', completeLguDromicPayload($incident))
            ->assertSessionHasNoErrors();
    }

    $seriesKeys = AssistanceRequest::query()
        ->where('submission_type', 'lgu_dromic_relief_request')
        ->pluck('lgu_dromic_series_key')
        ->all();

    AssistanceRequest::query()
        ->whereIn('lgu_dromic_series_key', $seriesKeys)
        ->each(function (AssistanceRequest $report) use ($user): void {
            $this->actingAs($user)
                ->post("/lgu/dromic-sitrep/{$report->id}/signed-copies", [
                    'signed_report' => UploadedFile::fake()->create('signed-report.pdf', 100, 'application/pdf'),
                ])
                ->assertSessionHasNoErrors();
        });

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep/relief-requests', [
            'incident_series_keys' => $seriesKeys,
            'requested_fni_items' => [[
                'fni_library_item_id' => $fniItem->id,
                'requested_quantity' => 25,
            ]],
            'signed_request' => UploadedFile::fake()->create('signed-consolidated-request.pdf', 100, 'application/pdf'),
        ])
        ->assertRedirect('/lgu/dromic-sitrep?tab=requests')
        ->assertSessionHasNoErrors();

    $request = AssistanceRequest::query()->where('purpose', 'Relief Augmentation')->latest('id')->firstOrFail();
    expect(data_get($request->lgu_dromic_payload, 'standalone_relief_request'))->toBeTrue()
        ->and(data_get($request->lgu_dromic_payload, 'linked_incidents'))->toHaveCount(2)
        ->and(data_get($request->lgu_dromic_payload, 'incident_type'))->toBe('Fire Incident')
        ->and($request->lgu_signed_request_path)->not->toBeNull()
        ->and($request->lgu_signed_report_path)->toBeNull()
        ->and($request->lguDromicRequestedItems()->count())->toBe(1);
    Storage::disk('public')->assertExists($request->lgu_signed_request_path);

    $this->actingAs($user)
        ->post("/lgu/dromic-sitrep/{$request->id}/submit")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($request->fresh()->lgu_report_status)->toBe('submitted')
        ->and($request->fresh()->lgu_relief_validation_status)->toBe('pending_review')
        ->and($request->fresh()->lgu_dromic_validation_status)->toBeNull();

    $this->actingAs($user)
        ->get('/lgu/dromic-sitrep')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('reliefRequestIncidentOptions', []));
});

it('hands off a consolidated lump request to DRRS with linked incidents and consolidated FNI', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();
    $drrs = User::where('email', 'drrs@example.test')->firstOrFail();
    $fniItem = FniLibraryItem::query()->create([
        'item_category' => 'Food',
        'item_name' => 'Family Food Pack',
        'brand_description' => 'Standard family food pack',
        'unit_of_measure' => 'box',
    ]);

    foreach ([
        ['incident_name' => 'Purok One Fire', 'incident_date' => '2026-08-01', 'occurrence_started_at' => '2026-08-01T08:00', 'information_received_at' => '2026-08-01T09:00', 'incident_ended_at' => '2026-08-01T10:00', 'area_rows' => [['area' => 'Test Barangay', 'affected_families' => 10, 'affected_persons' => 40]]],
        ['incident_name' => 'Purok Two Fire', 'incident_date' => '2026-08-02', 'occurrence_started_at' => '2026-08-02T09:00', 'information_received_at' => '2026-08-02T10:00', 'incident_ended_at' => '2026-08-02T11:00', 'area_rows' => [['area' => 'Test Barangay', 'affected_families' => 15, 'affected_persons' => 60]]],
    ] as $incident) {
        $this->actingAs($user)
            ->post('/lgu/dromic-sitrep', completeLguDromicPayload($incident))
            ->assertSessionHasNoErrors();
    }

    $seriesKeys = AssistanceRequest::query()
        ->where('submission_type', 'lgu_dromic_relief_request')
        ->pluck('lgu_dromic_series_key')
        ->all();

    AssistanceRequest::query()
        ->whereIn('lgu_dromic_series_key', $seriesKeys)
        ->each(function (AssistanceRequest $report) use ($user): void {
            $this->actingAs($user)
                ->post("/lgu/dromic-sitrep/{$report->id}/signed-copies", [
                    'signed_report' => UploadedFile::fake()->create('signed-report.pdf', 100, 'application/pdf'),
                ])
                ->assertSessionHasNoErrors();
        });

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep/relief-requests', [
            'incident_series_keys' => $seriesKeys,
            'requested_fni_items' => [[
                'fni_library_item_id' => $fniItem->id,
                'requested_quantity' => 25,
            ]],
            'signed_request' => UploadedFile::fake()->create('signed-consolidated-request.pdf', 100, 'application/pdf'),
        ])
        ->assertSessionHasNoErrors();

    $lump = AssistanceRequest::query()
        ->where('lgu_dromic_payload->standalone_relief_request', true)
        ->latest('id')
        ->firstOrFail();

    $this->actingAs($user)
        ->post("/lgu/dromic-sitrep/{$lump->id}/submit")
        ->assertSessionHasNoErrors();

    $this->actingAs($drrs)
        ->patch("/dromic/lgu-reports/{$lump->id}/relief-validation", [
            'validation_status' => 'validated_no_findings',
            'review_note' => 'Lump request letter validated for multi-incident handoff.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $fniRequest = AssistanceRequest::query()
        ->where('source_lgu_dromic_request_id', $lump->id)
        ->where('submission_type', 'fni_request')
        ->firstOrFail();

    expect($fniRequest->incident_count)->toBe(2)
        ->and($fniRequest->items()->count())->toBe(1)
        ->and((float) $fniRequest->items()->first()->requested_quantity)->toBe(25.0)
        ->and(data_get($fniRequest->assessment_form_data, 'standalone_relief_request'))->toBeTrue()
        ->and(data_get($fniRequest->assessment_form_data, 'linked_incidents'))->toHaveCount(2)
        ->and(data_get($fniRequest->assessment_form_data, 'incidents'))->toHaveCount(2)
        ->and(collect(data_get($fniRequest->assessment_form_data, 'incidents'))->pluck('incident_details')->all())
        ->toContain('Purok One Fire', 'Purok Two Fire')
        ->and(collect(data_get($fniRequest->assessment_form_data, 'incidents'))->pluck('incident_type')->unique()->values()->all())
        ->toBe(['Fire Incident'])
        ->and(collect(data_get($fniRequest->assessment_form_data, 'incidents'))->contains(
            fn ($row): bool => str_contains(Str::lower((string) data_get($row, 'incident_type')), 'consolidated')
        ))->toBeFalse();
});

it('omits incidents without an uploaded signed report from consolidated relief options', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();
    $fniItem = FniLibraryItem::query()->create([
        'item_category' => 'Food',
        'item_name' => 'Family Food Pack',
        'brand_description' => 'Standard family food pack',
        'unit_of_measure' => 'box',
    ]);

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', completeLguDromicPayload(['incident_name' => 'Unsigned Fire']))
        ->assertSessionHasNoErrors();

    $unsigned = AssistanceRequest::query()->where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();

    $this->actingAs($user)
        ->get('/lgu/dromic-sitrep')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('reliefRequestIncidentOptions', []));

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep/relief-requests', [
            'incident_series_keys' => [$unsigned->lgu_dromic_series_key],
            'requested_fni_items' => [[
                'fni_library_item_id' => $fniItem->id,
                'requested_quantity' => 10,
            ]],
            'signed_request' => UploadedFile::fake()->create('signed-consolidated-request.pdf', 100, 'application/pdf'),
        ])
        ->assertSessionHasErrors(['incident_series_keys' => 'Every selected incident must have an uploaded signed DROMIC report.']);
});

it('keeps per-incident FNI needs without a request letter and exposes them for lump requests', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();
    $fniItem = FniLibraryItem::query()->create([
        'item_category' => 'Food',
        'item_name' => 'Family Food Pack',
        'brand_description' => 'Standard family food pack',
        'unit_of_measure' => 'box',
    ]);

    foreach ([
        ['quantity' => 10, 'incident_name' => 'Purok One Fire needs', 'incident_date' => '2026-08-01', 'occurrence_started_at' => '2026-08-01T08:00', 'information_received_at' => '2026-08-01T09:00', 'incident_ended_at' => '2026-08-01T10:00'],
        ['quantity' => 15, 'incident_name' => 'Purok Two Fire needs', 'incident_date' => '2026-08-02', 'occurrence_started_at' => '2026-08-02T09:00', 'information_received_at' => '2026-08-02T10:00', 'incident_ended_at' => '2026-08-02T11:00'],
    ] as $incident) {
        $this->actingAs($user)
            ->post('/lgu/dromic-sitrep', completeLguDromicPayload([
                'incident_name' => $incident['incident_name'],
                'incident_date' => $incident['incident_date'],
                'occurrence_started_at' => $incident['occurrence_started_at'],
                'information_received_at' => $incident['information_received_at'],
                'incident_ended_at' => $incident['incident_ended_at'],
                'has_relief_request' => false,
                'requested_fni_items' => [[
                    'fni_library_item_id' => $fniItem->id,
                    'requested_quantity' => $incident['quantity'],
                ]],
            ]))
            ->assertSessionHasNoErrors();
    }

    $reports = AssistanceRequest::query()
        ->where('submission_type', 'lgu_dromic_relief_request')
        ->orderBy('id')
        ->get();

    expect($reports)->toHaveCount(2)
        ->and($reports->every(fn (AssistanceRequest $report): bool => blank($report->lgu_relief_request_reference)))->toBeTrue()
        ->and((int) data_get($reports[0]->lgu_dromic_payload, 'requested_fni_items.0.requested_quantity'))->toBe(10)
        ->and((int) data_get($reports[1]->lgu_dromic_payload, 'requested_fni_items.0.requested_quantity'))->toBe(15)
        ->and($reports[0]->lguDromicRequestedItems()->count())->toBe(1)
        ->and($reports[1]->lguDromicRequestedItems()->count())->toBe(1);

    foreach ($reports as $report) {
        $this->actingAs($user)
            ->post("/lgu/dromic-sitrep/{$report->id}/signed-copies", [
                'signed_report' => UploadedFile::fake()->create('signed-report.pdf', 100, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors();
    }

    $this->actingAs($user)
        ->get('/lgu/dromic-sitrep')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('reliefRequestIncidentOptions', 2)
            ->where('reliefRequestIncidentOptions.0.requested_fni_items.0.fni_library_item_id', $fniItem->id)
            ->where('reliefRequestIncidentOptions.1.requested_fni_items.0.fni_library_item_id', $fniItem->id));
});

it('does not carry prior FNI needs or include-request onto the next SitRep in the series', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();
    $fniItem = FniLibraryItem::query()->create([
        'item_category' => 'Food',
        'item_name' => 'Family Food Pack',
        'brand_description' => 'Standard family food pack',
        'unit_of_measure' => 'box',
    ]);

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', completeLguDromicPayload([
            'has_relief_request' => false,
            'requested_fni_items' => [[
                'fni_library_item_id' => $fniItem->id,
                'requested_quantity' => 20,
            ]],
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $first = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();
    expect(data_get($first->lgu_dromic_payload, 'requested_fni_items'))->toHaveCount(1);

    $this->actingAs($user)
        ->post("/lgu/dromic-sitrep/{$first->id}/submit")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', completeLguDromicPayload([
            'report_series_key' => $first->lgu_dromic_series_key,
            'information_received_at' => '2026-07-27T12:00',
            'has_relief_request' => false,
            'requested_fni_items' => [],
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $second = AssistanceRequest::query()
        ->where('lgu_dromic_series_key', $first->lgu_dromic_series_key)
        ->where('lgu_dromic_report_number', 2)
        ->firstOrFail();

    expect(data_get($second->lgu_dromic_payload, 'requested_fni_items', []))->toBe([])
        ->and((bool) data_get($second->lgu_dromic_payload, 'has_relief_request'))->toBeFalse()
        ->and($second->lgu_relief_request_reference)->toBeNull()
        ->and($second->lguDromicRequestedItems()->count())->toBe(0)
        ->and(data_get($first->fresh()->lgu_dromic_payload, 'requested_fni_items'))->toHaveCount(1);
});

it('allows unresolved sections in drafts but requires an entry or N A choice before finalizing', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();
    $payload = completeLguDromicPayload();
    $payload['not_applicable_sections'] = [];

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', [...$payload, 'submission_status' => 'draft'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $draft = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();
    expect($draft->lgu_report_status)->toBe('draft');

    $this->actingAs($user)
        ->patch("/lgu/dromic-sitrep/{$draft->id}", $payload)
        ->assertSessionHasErrors([
            'not_applicable_sections' => 'Inside Evacuation Centers: Add at least one entry or mark this section N/A before saving as final.',
        ]);

    expect($draft->fresh()->lgu_report_status)->toBe('draft');
});

it('rejects placeholder Situation Overview text when saving a final report', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', completeLguDromicPayload([
            'narrative' => 'Not applicable, details to follow.',
        ]))
        ->assertSessionHasErrors(['narrative']);

    expect(AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->count())->toBe(0);
});

it('requires information received and incident ended timestamps to follow occurrence', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', completeLguDromicPayload([
            'information_received_at' => '2026-07-27T07:59',
        ]))
        ->assertSessionHasErrors(['information_received_at']);

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', completeLguDromicPayload([
            'incident_status' => 'Ended',
            'report_classification' => 'first_and_final',
            'incident_ended_at' => '2026-07-27T07:59',
        ]))
        ->assertSessionHasErrors(['incident_ended_at']);

    expect(AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->count())->toBe(0);
});

it('rejects future information received and incident ended timestamps', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();
    $future = now()->addDay()->format('Y-m-d\TH:i');

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', completeLguDromicPayload([
            'occurrence_started_at' => $future,
            'information_received_at' => $future,
        ]))
        ->assertSessionHasErrors(['occurrence_started_at']);

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', completeLguDromicPayload([
            'information_received_at' => $future,
        ]))
        ->assertSessionHasErrors(['information_received_at']);

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', completeLguDromicPayload([
            'incident_status' => 'Ended',
            'report_classification' => 'first_and_final',
            'incident_ended_at' => $future,
        ]))
        ->assertSessionHasErrors(['incident_ended_at']);
});

it('keeps drafts editable, lets LGU reopen unsubmitted finals, and locks encoding after DSWD submission', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();

    $this->actingAs($user)->post('/lgu/dromic-sitrep', [
        'submission_status' => 'draft',
        'incident_name' => 'Initial draft',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $report = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();
    expect($report->lgu_report_status)->toBe('draft');

    $this->actingAs($user)
        ->patch("/lgu/dromic-sitrep/{$report->id}", completeLguDromicPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('preview_report_id', $report->id);

    $report->refresh();
    expect($report->lgu_report_status)->toBe('final')
        ->and($report->status)->toBe('final')
        ->and($report->submitted_at)->toBeNull()
        ->and($report->lgu_finalized_at)->not->toBeNull();

    $this->actingAs($user)
        ->get("/lgu/dromic-sitrep/{$report->id}/pdf")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->actingAs($user)
        ->patch("/lgu/dromic-sitrep/{$report->id}", completeLguDromicPayload(['submission_status' => 'draft']))
        ->assertStatus(422);

    $this->actingAs($user)
        ->post("/lgu/dromic-sitrep/{$report->id}/amendment-request", [
            'target' => 'report',
            'reason' => 'Unsubmitted finals must reopen directly instead of requesting DRIMS amendment permission.',
        ])
        ->assertStatus(422);

    $this->actingAs($user)
        ->post("/lgu/dromic-sitrep/{$report->id}/reopen")
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('reopen_draft_id', $report->id);

    $report->refresh();
    expect($report->lgu_report_status)->toBe('draft')
        ->and($report->lgu_finalized_at)->toBeNull()
        ->and($report->lgu_signed_report_path)->toBeNull();

    $this->actingAs($user)
        ->patch("/lgu/dromic-sitrep/{$report->id}", completeLguDromicPayload([
            'submission_status' => 'draft',
            'incident_name' => 'Revised before submit',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($report->fresh()->lgu_dromic_payload['incident_name'])->toBe('Revised before submit');

    $this->actingAs($user)
        ->patch("/lgu/dromic-sitrep/{$report->id}", completeLguDromicPayload([
            'incident_name' => 'Revised before submit',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($user)
        ->post("/lgu/dromic-sitrep/{$report->id}/submit")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($report->fresh()->lgu_report_status)->toBe('advance_submitted')
        ->and($report->fresh()->lgu_submitted_to_dswd_at)->not->toBeNull();

    $this->actingAs($user)
        ->post("/lgu/dromic-sitrep/{$report->id}/reopen")
        ->assertStatus(422);

    $this->actingAs($user)
        ->patch("/lgu/dromic-sitrep/{$report->id}", completeLguDromicPayload(['submission_status' => 'draft']))
        ->assertStatus(422);
});

it('shows a saved draft immediately to every authorized user of the same LGU', function (): void {
    $this->seed(DatabaseSeeder::class);
    $owner = User::where('email', 'superadmin@example.test')->firstOrFail();
    $owner->update([
        'lgu_name' => 'TUBOD',
        'lgu_level' => 'MLGU',
        'lgu_psgc_code' => '1606727000',
    ]);

    $this->actingAs($owner)
        ->post('/lgu/dromic-sitrep', [
            'submission_status' => 'draft',
            'incident_name' => 'Tubod flood monitoring draft',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $report = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();
    expect($report->lgu_psgc_code)->toBe($owner->lgu_psgc_code);

    $this->actingAs($owner)
        ->get('/lgu/dromic-sitrep')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lgu/DromicRequests/Index')
            ->where('requests.data.0.id', $report->id)
            ->where('requests.data.0.lgu_report_status', 'draft'));

    $colleague = $owner->replicate();
    $colleague->email = 'second-tubod-dromic-user@example.test';
    $colleague->username = 'second-tubod-dromic-user';
    $colleague->save();
    $colleague->syncRoles($owner->roles);

    $this->actingAs($colleague)
        ->get('/lgu/dromic-sitrep')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requests.data.0.id', $report->id)
            ->where('requests.data.0.reference_number', $report->reference_number));

    $this->actingAs($colleague)
        ->patch("/lgu/dromic-sitrep/{$report->id}", [
            'submission_status' => 'draft',
            'incident_name' => 'Updated by the second Tubod user',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(data_get($report->fresh()->lgu_dromic_payload, 'incident_name'))->toBe('Updated by the second Tubod user');
});

it('allows an incomplete requested FNI quantity to remain in an editable draft', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();
    $fniItem = FniLibraryItem::query()->create([
        'item_category' => 'Food Item',
        'item_name' => 'Family Food Pack',
        'brand_description' => '',
        'unit_of_measure' => 'pack',
    ]);

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', [
            'submission_status' => 'draft',
            'incident_name' => 'Draft relief request',
            'has_relief_request' => true,
            'requested_fni_items' => [
                ['fni_library_item_id' => $fniItem->id, 'requested_quantity' => ''],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $report = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();
    expect(data_get($report->lgu_dromic_payload, 'requested_fni_items.0.fni_library_item_id'))->toBe($fniItem->id)
        ->and($report->lguDromicRequestedItems()->count())->toBe(0)
        ->and($report->lgu_relief_request_reference)->toStartWith('LGU-REQ-');
});

it('shows only explicitly coded relief requests in the requests tab', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();

    $this->actingAs($user)->post('/lgu/dromic-sitrep', [
        'submission_status' => 'draft',
        'incident_name' => 'Report without request',
        'has_relief_request' => false,
    ])->assertRedirect();
    $this->actingAs($user)->post('/lgu/dromic-sitrep', [
        'submission_status' => 'draft',
        'incident_name' => 'Report with request',
        'has_relief_request' => true,
    ])->assertRedirect();

    $requestRecord = AssistanceRequest::whereNotNull('lgu_relief_request_reference')->firstOrFail();
    expect(AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->whereNull('lgu_relief_request_reference')->count())->toBe(1);

    $this->actingAs($user)
        ->get('/lgu/dromic-sitrep?tab=requests')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requests.data', 1)
            ->where('requests.data.0.id', $requestRecord->id)
            ->where('requests.data.0.lgu_relief_request_reference', $requestRecord->lgu_relief_request_reference));
});

it('counts draft saves and keeps a terminal incident open until its signed report is validated', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();
    $user->update([
        'lgu_name' => 'TUBOD',
        'lgu_level' => 'MLGU',
        'lgu_psgc_code' => '1606727000',
    ]);

    $this->actingAs($user)->post('/lgu/dromic-sitrep', [
        'submission_status' => 'draft',
        'incident_name' => 'Thunderstorm incident series',
        'incident_type' => 'Effects of Thunderstorms',
        'incident_specific_details' => 'Localized thunderstorms over Tubod',
        'incident_date' => '2026-07-27',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $draft = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();
    $this->actingAs($user)->patch("/lgu/dromic-sitrep/{$draft->id}", [
        'submission_status' => 'draft',
        'incident_name' => 'Thunderstorm incident series',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($draft->fresh()->lgu_dromic_draft_save_count)->toBe(2);

    $seriesPayload = completeLguDromicPayload([
        'incident_name' => 'Thunderstorm incident series',
        'incident_type' => 'Effects of Thunderstorms',
        'incident_specific_details' => 'Localized thunderstorms over Tubod',
        'incident_date' => '2026-07-27',
        'report_series_key' => $draft->lgu_dromic_series_key,
    ]);

    $this->actingAs($user)->patch("/lgu/dromic-sitrep/{$draft->id}", $seriesPayload)
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($draft->fresh()->lgu_dromic_report_number)->toBe(1);

    $this->actingAs($user)->post('/lgu/dromic-sitrep', $seriesPayload)
        ->assertSessionHasErrors('incident_name');

    $this->actingAs($user)->post("/lgu/dromic-sitrep/{$draft->id}/submit")
        ->assertRedirect()->assertSessionHasNoErrors();

    $this->actingAs($user)->post('/lgu/dromic-sitrep', [
        ...$seriesPayload,
        'information_received_at' => '2026-07-27T12:00',
    ])
        ->assertRedirect()->assertSessionHasNoErrors();
    $second = AssistanceRequest::where('lgu_dromic_series_key', $draft->lgu_dromic_series_key)->latest('id')->firstOrFail();
    expect($second->lgu_dromic_report_number)->toBe(2)
        ->and(data_get($second->lgu_dromic_payload, 'information_received_at'))->toBe('2026-07-27T09:00');

    $this->actingAs($user)->post("/lgu/dromic-sitrep/{$second->id}/submit")
        ->assertRedirect()->assertSessionHasNoErrors();

    $this->actingAs($user)->post('/lgu/dromic-sitrep', [
        ...$seriesPayload,
        'report_classification' => 'terminal',
        'incident_status' => 'Ended',
        'incident_ended_at' => '2026-07-27 18:00:00',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $terminal = AssistanceRequest::where('lgu_dromic_series_key', $draft->lgu_dromic_series_key)->latest('id')->firstOrFail();

    expect($terminal->lgu_dromic_report_number)->toBe(3)
        ->and($terminal->lgu_dromic_report_classification)->toBe('terminal')
        ->and($terminal->lgu_dromic_terminal_at)->not->toBeNull();

    $this->actingAs($user)->post('/lgu/dromic-sitrep', [
        'submission_status' => 'draft',
        'report_series_key' => $draft->lgu_dromic_series_key,
        'incident_name' => 'Late draft that must be blocked',
    ])->assertSessionHasErrors('report_classification');

    $this->actingAs($user)->get('/lgu/dromic-sitrep')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('reportFilters.tab', 'incidents')
            ->has('incidentGroups', 1)
            ->where('incidentGroups.0.series_key', $draft->lgu_dromic_series_key)
            ->where('incidentGroups.0.report_count', 3)
            ->where('incidentGroups.0.finalized_count', 3)
            ->where('incidentGroups.0.is_closed', false));

    $this->actingAs($user)->get('/lgu/dromic-sitrep?tab=reports&classification=terminal&series_key='.urlencode($draft->lgu_dromic_series_key))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('reportFilters.tab', 'reports')
            ->where('reportFilters.classification', 'terminal')
            ->has('requests.data', 1)
            ->where('requests.data.0.id', $terminal->id));
});

it('supports a first and final report only as the first finalized report of an ended incident', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();

    $payload = completeLguDromicPayload([
        'incident_name' => 'Short-duration fire incident',
        'incident_type' => 'Fire Incident',
        'incident_specific_details' => 'Residential fire extinguished on the same day',
        'incident_status' => 'Ended',
        'incident_ended_at' => '2026-07-27 12:00:00',
        'report_classification' => 'first_and_final',
    ]);

    $this->actingAs($user)->post('/lgu/dromic-sitrep', $payload)
        ->assertRedirect()->assertSessionHasNoErrors();

    $report = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();
    expect($report->lgu_dromic_report_number)->toBe(1)
        ->and($report->lgu_dromic_report_classification)->toBe('first_and_final')
        ->and($report->lgu_dromic_terminal_at)->not->toBeNull();

    $this->actingAs($user)->post('/lgu/dromic-sitrep', [
        'submission_status' => 'draft',
        'report_series_key' => $report->lgu_dromic_series_key,
        'incident_name' => 'Improper follow-up',
    ])->assertSessionHasErrors('report_classification');
});

it('finalizes a complete LGU draft when optional sections are marked not applicable and derives the LGU province', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();
    $user->update([
        'lgu_name' => 'TUBOD',
        'lgu_level' => 'MLGU',
        'lgu_psgc_code' => '1606727000',
        'area_of_assignment' => 'Tubod, Surigao del Norte',
    ]);

    $payload = completeLguDromicPayload([
        'province' => null,
        'municipality' => null,
        'not_applicable_sections' => [
            'inside_ec',
            'outside_ec',
            'damaged_houses',
            'assistance',
            'related_incidents',
            'casualties',
            'infrastructure_damage',
            'agriculture_damage',
            'class_suspension',
            'work_suspension',
            'roads_bridges',
            'power_lifelines',
            'water_lifelines',
            'communication_lifelines',
            'seaports',
            'airports',
            'land_transport_terminals',
            'stranded_transport',
            'calamity_declaration',
            'preemptive_evacuation',
            'cluster_gaps',
            'photo_documentation',
        ],
        'evacuation_center_rows' => [],
        'assistance_rows' => [],
    ]);

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', $payload)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $report = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();
    expect($report->province)->toBe('Surigao del Norte')
        ->and($report->municipality)->toBe('TUBOD')
        ->and($report->lgu_report_status)->toBe('final')
        ->and(data_get($report->lgu_dromic_payload, 'not_applicable_sections'))->toContain('inside_ec', 'photo_documentation');
});

it('accepts valid Inside EC family and person totals that represent different affected subsets', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();

    $payload = completeLguDromicPayload([
        'area_rows' => [[
            'area' => 'Test Barangay',
            'affected_families' => 5,
            'affected_persons' => 10,
        ]],
        'not_applicable_sections' => [
            'outside_ec',
            'damaged_houses',
            'assistance',
            'related_incidents',
            'casualties',
            'infrastructure_damage',
            'agriculture_damage',
            'class_suspension',
            'work_suspension',
            'roads_bridges',
            'power_lifelines',
            'water_lifelines',
            'communication_lifelines',
            'seaports',
            'airports',
            'land_transport_terminals',
            'stranded_transport',
            'calamity_declaration',
            'preemptive_evacuation',
            'cluster_gaps',
            'photo_documentation',
        ],
        'evacuation_center_rows' => [[
            'barangay_address' => 'Test Barangay',
            'evacuation_center' => 'Test National High School',
            'families_cum' => 5,
            'families_now' => 4,
            'persons_cum' => 10,
            'persons_now' => 9,
            'barangay_origin' => 'Test Barangay',
            'classrooms_used' => 1,
            'disaggregation_completed' => true,
            'disaggregation' => [
                'age_sex' => [
                    'adult' => [
                        'male_cum' => 10,
                        'male_now' => 9,
                        'female_cum' => 0,
                        'female_now' => 0,
                    ],
                ],
                'sectoral' => [],
            ],
        ]],
    ]);
    $payload['not_applicable_sections'] = array_values(array_diff($payload['not_applicable_sections'], ['inside_ec']));

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', $payload)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->count())->toBe(1);
});

it('requires paired sectoral CUM and NOW values while allowing an explicit zero NOW', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();
    $payloadFor = function (mixed $maleCum, mixed $maleNow): array {
        $payload = completeLguDromicPayload([
            'area_rows' => [[
                'area' => 'Test Barangay',
                'affected_families' => 5,
                'affected_persons' => 10,
            ]],
            'evacuation_center_rows' => [[
                'barangay_address' => 'Test Barangay',
                'evacuation_center' => 'Test National High School',
                'families_cum' => 5,
                'families_now' => 0,
                'persons_cum' => 10,
                'persons_now' => 0,
                'barangay_origin' => 'Test Barangay',
                'classrooms_used' => 1,
                'disaggregation_completed' => true,
                'disaggregation' => [
                    'age_sex' => [
                        'adult' => [
                            'male_cum' => 5,
                            'male_now' => 0,
                            'female_cum' => 5,
                            'female_now' => 0,
                        ],
                    ],
                    'sectoral' => [
                        'pwds' => [
                            'male_cum' => $maleCum,
                            'male_now' => $maleNow,
                            'female_cum' => 0,
                            'female_now' => 0,
                        ],
                    ],
                ],
            ]],
        ]);
        $payload['not_applicable_sections'] = array_values(array_diff($payload['not_applicable_sections'], ['inside_ec']));

        return $payload;
    };

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', $payloadFor(1, ''))
        ->assertSessionHasErrors([
            'evacuation_center_rows.0.disaggregation' => 'Persons with Disabilities (PWDs) male: CUM and NOW must both be encoded. Enter 0 when there is no count.',
        ]);

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', $payloadFor(6, 0))
        ->assertSessionHasErrors([
            'evacuation_center_rows.0.disaggregation' => 'Persons with Disabilities (PWDs): male CUM cannot exceed the male Age/Sex CUM total (5).',
        ]);

    $unchangedPopulationPayload = $payloadFor(0, 0);
    data_set($unchangedPopulationPayload, 'evacuation_center_rows.0.families_now', 5);
    data_set($unchangedPopulationPayload, 'evacuation_center_rows.0.persons_now', 10);
    data_set($unchangedPopulationPayload, 'evacuation_center_rows.0.disaggregation.age_sex.adult.male_now', 5);
    data_set($unchangedPopulationPayload, 'evacuation_center_rows.0.disaggregation.age_sex.adult.female_now', 5);
    data_set($unchangedPopulationPayload, 'evacuation_center_rows.0.disaggregation.sectoral.single_headed_family', [
        'male_cum' => 5,
        'male_now' => 0,
        'female_cum' => 5,
        'female_now' => 0,
    ]);
    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', $unchangedPopulationPayload)
        ->assertSessionHasErrors([
            'evacuation_center_rows.0.disaggregation' => 'Single-Headed Family: male NOW must equal male CUM because the EC male Age/Sex population is unchanged at 5.',
        ]);

    data_set($unchangedPopulationPayload, 'evacuation_center_rows.0.disaggregation.sectoral.single_headed_family.male_now', 5);
    data_set($unchangedPopulationPayload, 'evacuation_center_rows.0.disaggregation.sectoral.single_headed_family.female_now', 5);
    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', $unchangedPopulationPayload)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

});

it('retains incident identity while allowing corrected sectoral data with unchanged EC totals', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();
    $reportPayload = function (int $personsCum, int $sectoralCum): array {
        $payload = completeLguDromicPayload([
            'area_rows' => [[
                'area' => 'Test Barangay',
                'affected_families' => 5,
                'affected_persons' => $personsCum,
            ]],
            'evacuation_center_rows' => [[
                'barangay_address' => 'Test Barangay',
                'evacuation_center' => 'Test National High School',
                'families_cum' => 5,
                'families_now' => 0,
                'persons_cum' => $personsCum,
                'persons_now' => 0,
                'barangay_origin' => 'Test Barangay',
                'classrooms_used' => 1,
                'disaggregation_completed' => true,
                'disaggregation' => [
                    'age_sex' => [
                        'adult' => [
                            'male_cum' => $personsCum - 5,
                            'male_now' => 0,
                            'female_cum' => 5,
                            'female_now' => 0,
                        ],
                    ],
                    'sectoral' => [
                        'pwds' => [
                            'male_cum' => $sectoralCum,
                            'male_now' => 0,
                            'female_cum' => 0,
                            'female_now' => 0,
                        ],
                    ],
                ],
            ]],
        ]);
        $payload['not_applicable_sections'] = array_values(array_diff($payload['not_applicable_sections'], ['inside_ec']));

        return $payload;
    };

    $originalPayload = $reportPayload(10, 2);
    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', $originalPayload)
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $first = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();
    $this->actingAs($user)
        ->post("/lgu/dromic-sitrep/{$first->id}/submit")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $unchangedTotals = [
        ...$reportPayload(10, 3),
        'report_series_key' => $first->lgu_dromic_series_key,
        'incident_type' => 'Flood',
        'incident_specific_details' => 'Attempted replacement incident',
        'occurrence_started_at' => '2026-07-27T10:00',
        'information_received_at' => '2026-07-27T11:00',
    ];
    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', $unchangedTotals)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $second = AssistanceRequest::where('lgu_dromic_series_key', $first->lgu_dromic_series_key)->latest('id')->firstOrFail();
    expect(data_get($second->lgu_dromic_payload, 'incident_type'))->toBe($originalPayload['incident_type'])
        ->and(data_get($second->lgu_dromic_payload, 'incident_specific_details'))->toBeNull()
        ->and(data_get($second->lgu_dromic_payload, 'occurrence_started_at'))->toBe($originalPayload['occurrence_started_at'])
        ->and(data_get($second->lgu_dromic_payload, 'information_received_at'))->toBe($originalPayload['information_received_at'])
        ->and(data_get($second->lgu_dromic_payload, 'evacuation_center_rows.0.disaggregation.sectoral.pwds.male_cum'))->toBe(3);
});

it('rejects incomplete Inside EC Persons NOW when Families NOW is complete', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();

    $payload = completeLguDromicPayload([
        'area_rows' => [[
            'area' => 'Test Barangay',
            'affected_families' => 5,
            'affected_persons' => 10,
        ]],
        'evacuation_center_rows' => [[
            'barangay_address' => 'Test Barangay',
            'evacuation_center' => 'Test National High School',
            'families_cum' => 5,
            'families_now' => 5,
            'persons_cum' => 10,
            'persons_now' => 9,
            'barangay_origin' => 'Test Barangay',
            'classrooms_used' => 1,
            'disaggregation_completed' => true,
            'disaggregation' => [
                'age_sex' => [
                    'adult' => [
                        'male_cum' => 10,
                        'male_now' => 9,
                        'female_cum' => 0,
                        'female_now' => 0,
                    ],
                ],
                'sectoral' => [],
            ],
        ]],
    ]);
    $payload['not_applicable_sections'] = array_values(array_diff($payload['not_applicable_sections'], ['inside_ec']));

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', $payload)
        ->assertSessionHasErrors([
            'area_rows.0.affected_families' => 'Total displaced NOW families equal all affected families, but displaced persons do not equal affected persons. Correct the Inside/Outside EC counts so families and persons are fully accounted together.',
        ]);
});

it('rejects inconsistent fully-accounted displacement and invalid computed Non-IDP pairs', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();
    $outsidePayload = function (int $families, int $persons): array {
        $payload = completeLguDromicPayload();
        $payload['not_applicable_sections'] = array_values(array_diff($payload['not_applicable_sections'], ['outside_ec']));
        $payload['area_rows'] = [[
            'area' => 'Test Barangay',
            'affected_families' => 10,
            'affected_persons' => 20,
            'outside_ec_included' => true,
            'outside_ec_families_cum' => $families,
            'outside_ec_families_now' => $families,
            'outside_ec_persons_cum' => $persons,
            'outside_ec_persons_now' => $persons,
        ]];

        return $payload;
    };

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', $outsidePayload(10, 18))
        ->assertSessionHasErrors(['area_rows.0.affected_families']);

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', $outsidePayload(9, 20))
        ->assertSessionHasErrors(['area_rows.0.affected_families']);

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', $outsidePayload(1, 15))
        ->assertSessionHasErrors(['area_rows.0.affected_families']);

    $insidePayload = completeLguDromicPayload();
    $insidePayload['not_applicable_sections'] = array_values(array_diff($insidePayload['not_applicable_sections'], ['inside_ec']));
    $insidePayload['area_rows'] = [[
        'area' => 'Test Barangay',
        'affected_families' => 10,
        'affected_persons' => 20,
    ]];
    $insidePayload['evacuation_center_rows'] = [[
        'barangay_address' => 'Test Barangay',
        'evacuation_center' => 'Test Evacuation Center',
        'families_cum' => 10,
        'families_now' => 0,
        'persons_cum' => 18,
        'persons_now' => 0,
        'barangay_origin' => 'Test Barangay',
        'classrooms_used' => 1,
        'disaggregation_completed' => true,
        'disaggregation' => [
            'age_sex' => [
                'adult' => [
                    'male_cum' => 18,
                    'male_now' => 0,
                    'female_cum' => 0,
                    'female_now' => 0,
                ],
            ],
            'sectoral' => [],
        ],
    ]];

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', $insidePayload)
        ->assertSessionHasErrors(['area_rows.0.affected_families']);
});

it('excludes stale Inside and Outside EC values when both sections are marked N A', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();
    $payload = completeLguDromicPayload([
        'area_rows' => [[
            'area' => 'Test Barangay',
            'affected_families' => 5,
            'affected_persons' => 20,
            'outside_ec_included' => true,
            'outside_ec_families_cum' => 100,
            'outside_ec_families_now' => 100,
            'outside_ec_persons_cum' => 100,
            'outside_ec_persons_now' => 100,
        ]],
        'evacuation_center_rows' => [[
            'barangay_address' => 'Test Barangay',
            'evacuation_center' => 'Stale Evacuation Center',
            'families_cum' => 100,
            'families_now' => 100,
            'persons_cum' => 100,
            'persons_now' => 100,
            'barangay_origin' => 'Test Barangay',
            'classrooms_used' => 1,
            'disaggregation_completed' => true,
            'disaggregation' => [
                'age_sex' => [
                    'adult' => [
                        'male_cum' => 100,
                        'male_now' => 100,
                        'female_cum' => 0,
                        'female_now' => 0,
                    ],
                ],
                'sectoral' => [],
            ],
        ]],
    ]);

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', $payload)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $report = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->firstOrFail();
    expect(data_get($report->lgu_dromic_payload, 'evacuation_center_rows'))->toBe([])
        ->and(data_get($report->lgu_dromic_payload, 'area_rows.0.outside_ec_included'))->toBeFalse()
        ->and(data_get($report->lgu_dromic_payload, 'area_rows.0.outside_ec_families_cum'))->toBeNull();
});

it('renders the harmonized narrative report with template logos signatories ordered collages and no N A sections', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', completeLguDromicPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $report = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();
    $report->setAttribute('lgu_dromic_payload', [
        ...$report->lgu_dromic_payload,
        'has_relief_request' => true,
        'requested_fni_items' => [
            ['item_name' => 'Family Food Pack', 'requested_quantity' => 100, 'unit_of_measure' => 'pack'],
        ],
        'not_applicable_sections' => ['inside_ec', 'outside_ec', 'damaged_houses', 'assistance', 'related_incidents'],
        'photo_collage_rows' => [
            ['title' => 'First collage', 'data_url' => 'data:image/jpeg;base64,Zmlyc3QtY29sbGFnZQ=='],
            ['title' => 'Second collage', 'data_url' => 'data:image/jpeg;base64,c2Vjb25kLWNvbGxhZ2U='],
        ],
    ]);
    $report->setAttribute('lgu_submitted_to_dswd_at', Carbon::parse('2026-07-28 08:15:00'));
    $logo = 'data:image/png;base64,bG9nbw==';
    $html = view('documents.lgu-dromic', [
        'request' => $report->load('incident'),
        'reportProfile' => [
            'logos' => ['lgu' => $logo, 'dromic' => $logo, 'ldrrmc' => $logo],
            'signatories' => [
                'lswdo' => ['name' => 'Test LSWDO', 'position' => 'MSWDO'],
                'ldrrmo' => ['name' => 'Test LDRRMO', 'position' => 'MDRRMO'],
                'lce' => ['name' => 'Test Mayor', 'position' => 'Municipal Mayor'],
            ],
        ],
    ])->render();

    expect($html)->not->toContain('DRN:')
        ->and($html)->not->toContain('Not applicable')
        ->and($html)->not->toContain('Status of Displaced Population')
        ->and($html)->not->toContain('<h2>Damaged Houses</h2>')
        ->and($html)->not->toContain('<h2>Status of Assistance Provided</h2>')
        ->and($html)->not->toContain('<h2>Related Incidents</h2>')
        ->and($html)->not->toContain('Request for Relief Augmentation', 'Family Food Pack')
        ->and($html)->toContain('As of 28 July 2026, 08:15 AM')
        ->and(substr_count($html, 'class="brand-logo"'))->toBe(3)
        ->and($html)->toContain('Test LSWDO', 'Test LDRRMO', 'Test Mayor')
        ->and($html)->toContain('margin: 80px 26px 38px 38px')
        ->and($html)->toContain('text-align-last: left', 'class="narrative-paragraph"')
        ->and($html)->toContain('class="signature-companion"', 'Photo Documentation (continued)')
        ->and($html)->not->toContain('<div>LSWDO</div>', '<div>LDRRMO</div>', '<div>City/Municipal Mayor</div>')
        ->and(substr_count($html, '>Total<'))->toBeGreaterThanOrEqual(2)
        ->and(strpos($html, 'Gaps/Challenges and Status/Actions Undertaken'))->toBeLessThan(strpos($html, 'Response Actions and Interventions'))
        ->and(strpos($html, 'Zmlyc3QtY29sbGFnZQ=='))->toBeLessThan(strpos($html, 'c2Vjb25kLWNvbGxhZ2U='));
});

it('renders a live LGU narrative report preview from unsaved encoding values', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();

    $response = $this->actingAs($user)
        ->postJson('/lgu/dromic-sitrep/preview', completeLguDromicPayload([
            'narrative' => "The blaze was contained and extinguished by local responders.\n\nTwo families were displaced in Barangay Capayahan.",
        ]));

    $response->assertOk();
    $html = (string) $response->json('html');

    expect($html)
        ->toContain('LGU DROMIC / Situational Report No. 1 on the Fire Incident')
        ->toContain('Situation Overview')
        ->toContain('class="narrative-paragraph"')
        ->toContain('The blaze was contained and extinguished by local responders.')
        ->toContain('Two families were displaced in Barangay Capayahan.')
        ->toContain('Status of Affected Population')
        ->toContain('class="brand-header"')
        ->toContain('position: static')
        ->and($html)->not->toContain('Read-only encoded report');
});

it('renders one consolidated age sex and sectoral table for all completed evacuation centers', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', completeLguDromicPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $report = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();
    $payload = $report->lgu_dromic_payload;
    $payload['not_applicable_sections'] = array_values(array_diff(
        $payload['not_applicable_sections'],
        ['inside_ec'],
    ));
    $payload['evacuation_center_rows'] = [[
        'barangay_address' => 'Test Barangay',
        'evacuation_center' => 'Test National High School',
        'families_cum' => 5,
        'families_now' => 4,
        'persons_cum' => 10,
        'persons_now' => 8,
        'barangay_origin' => 'Test Barangay',
        'classrooms_used' => 1,
        'disaggregation_completed' => true,
        'disaggregation' => [
            'age_sex' => [
                'infant' => ['male_cum' => 2, 'male_now' => 1, 'female_cum' => 1, 'female_now' => 1],
                'adult' => ['male_cum' => 3, 'male_now' => 3, 'female_cum' => 4, 'female_now' => 3],
            ],
            'sectoral' => [
                'pwds' => ['male_cum' => 1, 'male_now' => 1, 'female_cum' => 2, 'female_now' => 1],
                'pregnant_women' => ['male_cum' => null, 'male_now' => null, 'female_cum' => 1, 'female_now' => 1],
            ],
        ],
    ], [
        'barangay_address' => 'Second Barangay',
        'evacuation_center' => 'Second Evacuation Center',
        'families_cum' => 2,
        'families_now' => 2,
        'persons_cum' => 5,
        'persons_now' => 5,
        'barangay_origin' => 'Second Barangay',
        'classrooms_used' => 0,
        'disaggregation_completed' => true,
        'disaggregation' => [
            'age_sex' => [
                'infant' => ['male_cum' => 1, 'male_now' => 1, 'female_cum' => 1, 'female_now' => 1],
                'adult' => ['male_cum' => 2, 'male_now' => 2, 'female_cum' => 1, 'female_now' => 1],
            ],
            'sectoral' => [
                'pwds' => ['male_cum' => 2, 'male_now' => 2, 'female_cum' => 0, 'female_now' => 0],
            ],
        ],
    ]];
    $report->setAttribute('lgu_dromic_payload', $payload);

    $html = view('documents.lgu-dromic', [
        'request' => $report->load('incident'),
        'reportProfile' => ['logos' => [], 'signatories' => []],
        'orientation' => 'landscape',
    ])->render();

    expect($html)->toContain(
        'Test National High School',
        'Second Evacuation Center',
        'Consolidated Sex, Age and Sectoral Disaggregated Data — All Listed Evacuation Centers',
        'Sex and Age Disaggregation',
        '0-6 months old',
        'Sectoral Group',
        'Persons with Disabilities (PWDs)',
        'Pregnant Women',
    )
        ->and(substr_count($html, 'Sex and Age Disaggregation'))->toBe(1)
        ->and(substr_count($html, 'Sectoral Group'))->toBe(1)
        ->and(substr_count($html, 'All age groups'))->toBe(1)
        ->and(substr_count($html, 'All sectoral groups'))->toBe(1)
        ->and($html)->not->toContain('1. Test National High School —', '2. Second Evacuation Center —');
});

it('omits all-zero consolidated age sex and sectoral tables from the narrative report', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();
    $this->actingAs($user)->post('/lgu/dromic-sitrep', completeLguDromicPayload())->assertRedirect();
    $report = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();
    $payload = $report->lgu_dromic_payload;
    $payload['not_applicable_sections'] = array_values(array_diff($payload['not_applicable_sections'], ['inside_ec']));
    $payload['evacuation_center_rows'] = [[
        'barangay_address' => 'Test Barangay',
        'evacuation_center' => 'Zero Count EC',
        'families_cum' => 0,
        'families_now' => 0,
        'persons_cum' => 0,
        'persons_now' => 0,
        'barangay_origin' => 'Test Barangay',
        'classrooms_used' => 0,
        'disaggregation_completed' => true,
        'disaggregation' => ['age_sex' => [], 'sectoral' => []],
    ]];
    $report->setAttribute('lgu_dromic_payload', $payload);

    $html = view('documents.lgu-dromic', [
        'request' => $report->load('incident'),
        'reportProfile' => ['logos' => [], 'signatories' => []],
        'orientation' => 'landscape',
    ])->render();

    expect($html)->toContain('Zero Count EC')
        ->and($html)->not->toContain('Consolidated Sex, Age and Sectoral Disaggregated Data', 'Sex and Age Disaggregation', 'All sectoral groups');
});

it('uses barangay names in the report title only when one or two barangays are affected', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();
    $this->actingAs($user)->post('/lgu/dromic-sitrep', completeLguDromicPayload())->assertRedirect();
    $report = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail()->load('incident');

    $renderTitle = function (array $barangays) use ($report): string {
        $copy = clone $report;
        $payload = $copy->lgu_dromic_payload;
        $payload['incident_type'] = 'Fire Incident';
        $payload['affected_barangays'] = $barangays;
        $copy->setAttribute('lgu_dromic_payload', $payload);

        return view('documents.lgu-dromic', [
            'request' => $copy,
            'reportProfile' => ['logos' => [], 'signatories' => []],
            'orientation' => 'portrait',
        ])->render();
    };

    expect($renderTitle(['Johnson']))
        ->toContain('on the Fire Incident in Brgy. Johnson, Municipality of Test, Agusan del Norte')
        ->and($renderTitle(['Johnson', 'Poblacion']))
        ->toContain('on the Fire Incident in Brgy. Johnson and Brgy. Poblacion, Municipality of Test, Agusan del Norte')
        ->and($renderTitle(['Johnson', 'Poblacion', 'San Isidro']))
        ->toContain('on the Fire Incident in Municipality of Test, Agusan del Norte')
        ->not->toContain('Brgy. Johnson', 'Brgy. Poblacion', 'Brgy. San Isidro');

    $report->municipality = 'Loreto, Agusan del Sur';
    $report->province = 'Agusan del Sur';
    expect($renderTitle(['Johnson']))
        ->toContain('on the Fire Incident in Brgy. Johnson, Loreto, Agusan del Sur')
        ->not->toContain('Agusan del Sur, Agusan del Sur');
});

it('selects PDF orientation from the widest table that will actually be rendered', function (): void {
    $controller = app(LguDromicRequestController::class);
    $orientation = new ReflectionMethod($controller, 'dromicPdfOrientation');
    $wideSections = [
        'inside_ec',
        'outside_ec',
        'damaged_houses',
        'assistance',
        'cluster_gaps',
        'related_incidents',
        'casualties',
        'infrastructure_damage',
        'agriculture_damage',
        'class_suspension',
        'work_suspension',
        'roads_bridges',
        'power_lifelines',
        'water_lifelines',
        'communication_lifelines',
        'seaports',
        'airports',
        'land_transport_terminals',
        'stranded_transport',
        'calamity_declaration',
        'preemptive_evacuation',
    ];

    expect($orientation->invoke($controller, [
        'not_applicable_sections' => $wideSections,
    ]))->toBe('portrait')
        ->and($orientation->invoke($controller, [
            'not_applicable_sections' => array_values(array_diff($wideSections, ['inside_ec'])),
        ]))->toBe('landscape')
        ->and($orientation->invoke($controller, [
            'not_applicable_sections' => $wideSections,
            'related_incident_rows' => [[
                'one' => 1,
                'two' => 2,
                'three' => 3,
                'four' => 4,
                'five' => 5,
                'six' => 6,
                'seven' => 7,
                'eight' => 8,
            ]],
        ]))->toBe('portrait');
});

it('requires completed past-tense response language in closed-report Situation Overviews', function (): void {
    $controller = app(LguDromicRequestController::class);
    $promptMethod = new ReflectionMethod($controller, 'situationOverviewSystemPrompt');
    $correctionMethod = new ReflectionMethod($controller, 'situationOverviewNeedsCorrection');
    $prompt = $promptMethod->invoke($controller);
    $ongoingNarrative = implode("\n\n", [
        'The incident affected the municipality.',
        'Validated figures described the affected population.',
        'The LGU continues to provide the encoded assistance.',
        'The report summarizes the validated situation.',
    ]);

    expect($prompt)
        ->toContain('For a Terminal Report or First and Final Report, treat every encoded response action as completed')
        ->and($prompt)->toContain('do not promise future or continuing response work')
        ->and($correctionMethod->invoke(
            $controller,
            $ongoingNarrative,
            'Report Classification: terminal',
        ))->toBeTrue();
});

it('builds fire Situation Overview prompt rules with fireout, barangay names, and omitted PAGASA when N/A', function (): void {
    $controller = app(LguDromicRequestController::class);
    $promptMethod = new ReflectionMethod($controller, 'situationOverviewSystemPrompt');
    $correctionMethod = new ReflectionMethod($controller, 'situationOverviewNeedsCorrection');
    $profileMethod = new ReflectionMethod($controller, 'situationOverviewIncidentProfile');
    $promptMethod->setAccessible(true);
    $correctionMethod->setAccessible(true);
    $profileMethod->setAccessible(true);

    $fireFacts = [
        'incident' => [
            'type' => 'Fire Incident',
            'affected_barangays' => ['San Juan'],
            'status' => 'Ended',
            'fireout' => '2 August 2026, 3:40 PM',
        ],
        'official_agency_advisories_status' => 'not_applicable',
        'official_agency_advisories_not_applicable' => true,
        'official_agency_advisories' => [],
    ];
    $prompt = $promptMethod->invoke($controller, $fireFacts);
    $profile = $profileMethod->invoke($controller, $fireFacts);
    $vagueNarrative = implode("\n\n", [
        'A fire affected one barangay according to PAGASA.',
        'Five families were affected.',
        'The LGU conducted local validation.',
        'The report presents the final validated situation.',
    ]);
    $factsString = 'Incident: '.json_encode($fireFacts['incident'])."\nOfficial Agency Advisories Status: not_applicable";

    expect($profile['kind'])->toBe('fire')
        ->and($profile['omit_warning_agencies'])->toBeTrue()
        ->and($prompt)->toContain('This is a fire incident')
        ->and($prompt)->toContain('fireout')
        ->and($prompt)->toContain('actual name(s) of the affected barangay')
        ->and($prompt)->toContain('never mention PAGASA or PHIVOLCS')
        ->and($prompt)->not->toContain('Never name, list, or enumerate the affected barangays')
        ->and($correctionMethod->invoke($controller, $vagueNarrative, $factsString, $fireFacts))->toBeTrue();
});

it('allows authorized DSWD users to fetch structured encoded report data without embedded image blobs', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', completeLguDromicPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $report = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();
    $payload = $report->lgu_dromic_payload;
    $payload['official_advisory_rows'] = [[
        'agency' => 'PAGASA',
        'extracted_text' => 'Localized thunderstorms may affect the locality.',
        'screenshot_data_url' => 'data:image/png;base64,c2NyZWVuc2hvdA==',
    ]];
    $payload['photo_collage_rows'] = [[
        'title' => 'Response collage',
        'data_url' => 'data:image/jpeg;base64,Y29sbGFnZQ==',
    ]];
    $report->update(['lgu_dromic_payload' => $payload]);

    $response = $this->actingAs($user)
        ->get("/lgu/dromic-sitrep/{$report->id}/encoded-data")
        ->assertOk()
        ->assertHeader('content-type', 'application/json; charset=UTF-8');

    $export = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
    expect(data_get($export, 'system_tracking_reference'))->toBe($report->reference_number)
        ->and(data_get($export, 'encoded_report.incident_name'))->toBe('Test Fire Incident')
        ->and(data_get($export, 'encoded_report.official_advisory_rows.0.screenshot_attached'))->toBeTrue()
        ->and(data_get($export, 'encoded_report.official_advisory_rows.0.screenshot_data_url'))->toBeNull()
        ->and(data_get($export, 'encoded_report.photo_collage_rows.0.image_attached'))->toBeTrue()
        ->and(data_get($export, 'encoded_report.photo_collage_rows.0.data_url'))->toBeNull();
});

it('shows staff the full encoded report in the read-only form payload while reserving JSON for DRIMS consolidation', function (): void {
    $this->seed(DatabaseSeeder::class);
    $owner = User::where('email', 'superadmin@example.test')->firstOrFail();
    $drrs = User::where('email', 'drrs@example.test')->firstOrFail();
    $drims = User::where('email', 'drims@example.test')->firstOrFail();

    $this->actingAs($owner)
        ->post('/lgu/dromic-sitrep', completeLguDromicPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $report = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();
    $this->actingAs($owner)
        ->post("/lgu/dromic-sitrep/{$report->id}/submit")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($drrs)
        ->get('/dromic/lgu-reports')
        ->assertInertia(fn (Assert $page) => $page
            ->where('reports.data.0.id', $report->id)
            ->where('reports.data.0.lgu_dromic_payload.incident_name', 'Test Fire Incident')
            ->where('reports.data.0.lgu_dromic_payload.area_rows.0.area', 'Test Barangay'));
    $this->actingAs($drrs)
        ->get("/lgu/dromic-sitrep/{$report->id}/encoded-data")
        ->assertForbidden();
    $this->actingAs($drims)
        ->get("/lgu/dromic-sitrep/{$report->id}/encoded-data")
        ->assertOk()
        ->assertHeader('content-type', 'application/json; charset=UTF-8');
});

it('keeps unsubmitted final reports private and creates a separate DRRS request only after approved relief routing', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    $owner = User::where('email', 'superadmin@example.test')->firstOrFail();
    $drrs = User::where('email', 'drrs@example.test')->firstOrFail();
    $drims = User::where('email', 'drims@example.test')->firstOrFail();
    $rros = User::where('email', 'rros@example.test')->firstOrFail();
    $drmdAa = User::where('email', 'drmd-aa@example.test')->firstOrFail();
    $chief = User::where('email', 'drmd-chief@example.test')->firstOrFail();
    $fniItem = FniLibraryItem::query()->create([
        'item_category' => 'Food Item',
        'item_name' => 'Family Food Pack',
        'brand_description' => '',
        'unit_of_measure' => 'pack',
    ]);

    $this->actingAs($owner)
        ->post('/lgu/dromic-sitrep', completeLguDromicPayload([
            'has_relief_request' => true,
            'relief_requested' => 'Family food packs and hygiene kits for affected families.',
            'requested_fni_items' => [
                ['fni_library_item_id' => $fniItem->id, 'requested_quantity' => 50],
            ],
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $report = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();
    expect($report->lguDromicRequestedItems()->count())->toBe(1)
        ->and((float) $report->lguDromicRequestedItems()->first()->requested_quantity)->toBe(50.0)
        ->and(data_get($report->lgu_dromic_payload, 'requested_fni_items.0.item_name'))->not->toBeNull();

    $this->actingAs($drrs)
        ->get('/requests')
        ->assertInertia(fn (Assert $page) => $page->where(
            'requests.data',
            fn ($rows): bool => collect($rows)->doesntContain('id', $report->id),
        ));
    $this->actingAs($drrs)
        ->get("/lgu/dromic-sitrep/{$report->id}/pdf?inline=1")
        ->assertForbidden();
    $this->actingAs($drims)
        ->get("/lgu/dromic-sitrep/{$report->id}/encoded-data")
        ->assertForbidden();
    $this->actingAs($drrs)
        ->get('/dromic/lgu-reports')
        ->assertInertia(fn (Assert $page) => $page->where('reports.data', []));
    $this->actingAs($drmdAa)
        ->get('/drmd-aa/requests')
        ->assertInertia(fn (Assert $page) => $page->where(
            'transactions.data',
            fn ($rows): bool => collect($rows)->doesntContain('source_lgu_dromic_request_id', $report->id),
        ));

    $this->actingAs($owner)
        ->post("/lgu/dromic-sitrep/{$report->id}/submit")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($drrs)
        ->get('/dromic/lgu-reports')
        ->assertInertia(fn (Assert $page) => $page->where('reports.data.0.id', $report->id));
    $this->actingAs($drims)
        ->get('/dromic/lgu-reports')
        ->assertInertia(fn (Assert $page) => $page->where('reports.data.0.id', $report->id));
    $this->actingAs($drrs)
        ->get("/lgu/dromic-sitrep/{$report->id}/pdf?inline=1")
        ->assertOk();
    $this->actingAs($drims)
        ->get("/lgu/dromic-sitrep/{$report->id}/encoded-data")
        ->assertOk();
    $this->actingAs($drmdAa)
        ->get('/drmd-aa/requests')
        ->assertInertia(fn (Assert $page) => $page->where(
            'transactions.data',
            fn ($rows): bool => collect($rows)->doesntContain('source_lgu_dromic_request_id', $report->id),
        ));
    $this->actingAs($drmdAa)
        ->patch("/drmd-aa/lgu-intake/{$report->id}/chief", ['remarks' => 'Advance copies must not be routed.'])
        ->assertStatus(422);

    $this->actingAs($owner)
        ->post("/lgu/dromic-sitrep/{$report->id}/signed-copies", [
            'signed_report' => UploadedFile::fake()->create('signed-report.pdf', 100, 'application/pdf'),
            'signed_request' => UploadedFile::fake()->create('signed-request.pdf', 100, 'application/pdf'),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $this->actingAs($drmdAa)
        ->get('/drmd-aa/requests')
        ->assertInertia(fn (Assert $page) => $page->where(
            'transactions.data',
            fn ($rows): bool => collect($rows)->doesntContain('source_lgu_dromic_request_id', $report->id),
        ));

    $this->actingAs($drims)
        ->patch("/dromic/lgu-reports/{$report->id}/validation", [
            'validation_status' => 'validated_no_findings',
            'review_note' => 'Signed DROMIC report passed validation.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $this->actingAs($drrs)
        ->patch("/dromic/lgu-reports/{$report->id}/relief-validation", [
            'validation_status' => 'validated_no_findings',
            'review_note' => 'Signed request letter passed validation.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $reliefRequest = AssistanceRequest::where('source_lgu_dromic_request_id', $report->id)->firstOrFail();
    $this->actingAs($rros)
        ->get("/lgu/dromic-sitrep/{$report->id}/signed-copy/request")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    $this->actingAs($drmdAa)
        ->get('/drmd-aa/requests')
        ->assertInertia(fn (Assert $page) => $page
            ->where('transactions.data.0.id', $reliefRequest->id)
            ->where('transactions.data.0.source_lgu_dromic_report.id', $report->id));
    $this->actingAs($chief)
        ->get('/drmd-chief/lgu-intake')
        ->assertInertia(fn (Assert $page) => $page->where('requests.data.0.id', $report->id));
    $this->actingAs($drmdAa)
        ->patch("/drmd-aa/lgu-intake/{$report->id}/drn", ['request_drn' => 'DRN-2026-07-001'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $reliefRequest->refresh();
    expect($reliefRequest->submission_type)->toBe('fni_request')
        ->and($reliefRequest->status)->toBe('endorsed')
        ->and($reliefRequest->purpose)->toBe('Relief Augmentation')
        ->and($reliefRequest->items()->count())->toBe(1)
        ->and((float) $reliefRequest->items()->first()->requested_quantity)->toBe(50.0)
        ->and($reliefRequest->request_drn)->toBe('DRN-2026-07-001')
        ->and($report->fresh()->submission_type)->toBe('lgu_dromic_relief_request')
        ->and($report->fresh()->status)->toBe('submitted_with_signed_copies');

    $this->actingAs($drrs)
        ->get('/requests?status=actionable&search='.urlencode($reliefRequest->reference_number))
        ->assertInertia(fn (Assert $page) => $page
            ->where('requests.data.0.id', $reliefRequest->id)
            ->where('requests.data.0.source_lgu_dromic_report.id', $report->id));
});

it('tracks an advance copy until the signed report is uploaded', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', completeLguDromicPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $report = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();

    $this->actingAs($user)
        ->post("/lgu/dromic-sitrep/{$report->id}/submit")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $report->refresh();
    expect($report->lgu_report_status)->toBe('advance_submitted')
        ->and($report->status)->toBe('advance_copy_submitted')
        ->and($report->completed_at)->toBeNull();

    $this->actingAs($user)
        ->post("/lgu/dromic-sitrep/{$report->id}/submit")
        ->assertUnprocessable();

    $this->actingAs($user)
        ->post("/lgu/dromic-sitrep/{$report->id}/signed-copies", [
            'signed_report' => UploadedFile::fake()->image('signed-report.png'),
        ])
        ->assertSessionHasErrors(['signed_report']);

    $this->actingAs($user)
        ->post("/lgu/dromic-sitrep/{$report->id}/signed-copies", [
            'signed_report' => UploadedFile::fake()->create('signed-report.pdf', 100, 'application/pdf'),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $report->refresh();
    expect($report->lgu_report_status)->toBe('submitted')
        ->and($report->status)->toBe('submitted_with_signed_copies')
        ->and($report->completed_at)->not->toBeNull();
    Storage::disk('public')->assertExists($report->lgu_signed_report_path);
});

it('requires both signed documents to complete a report with relief augmentation', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();
    $fniItem = FniLibraryItem::query()->create([
        'item_category' => 'Food Item',
        'item_name' => 'Family Food Pack',
        'brand_description' => '',
        'unit_of_measure' => 'pack',
    ]);

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', completeLguDromicPayload([
            'has_relief_request' => true,
            'relief_requested' => '100 family food packs',
            'requested_fni_items' => [
                ['fni_library_item_id' => $fniItem->id, 'requested_quantity' => 100],
            ],
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $report = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();
    $this->actingAs($user)->post("/lgu/dromic-sitrep/{$report->id}/submit")->assertRedirect();

    $this->actingAs($user)->post("/lgu/dromic-sitrep/{$report->id}/signed-copies", [
        'signed_report' => UploadedFile::fake()->create('signed-report.pdf', 100, 'application/pdf'),
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($report->fresh()->lgu_report_status)->toBe('advance_submitted');

    $this->actingAs($user)->post("/lgu/dromic-sitrep/{$report->id}/signed-copies", [
        'signed_request' => UploadedFile::fake()->create('signed-request.pdf', 100, 'application/pdf'),
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($report->fresh()->lgu_report_status)->toBe('submitted');
});

it('allows DSWD personnel to record and clear LGU report validation findings', function (): void {
    Storage::fake('public');
    Storage::fake('local');
    $this->seed(DatabaseSeeder::class);
    $owner = User::where('email', 'superadmin@example.test')->firstOrFail();
    $reviewer = User::where('email', 'drims@example.test')->firstOrFail();

    $this->actingAs($owner)
        ->post('/lgu/dromic-sitrep', completeLguDromicPayload())
        ->assertRedirect();
    $report = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();
    $this->actingAs($owner)->post("/lgu/dromic-sitrep/{$report->id}/submit")->assertRedirect();

    $screenshot = UploadedFile::fake()->createWithContent(
        'missing-page.png',
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
    );
    $this->actingAs($reviewer)
        ->post("/dromic/lgu-reports/{$report->id}/validation", [
            'validation_status' => 'needs_lgu_action',
            'review_note' => 'Page 2 is missing from the uploaded signed report. Please upload the complete PDF.',
            'screenshots' => [$screenshot],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $findingReport = $report->fresh();
    expect($findingReport->lgu_dromic_validation_status)->toBe('needs_lgu_action')
        ->and($findingReport->lgu_dromic_review_note)->toBe('Page 2 is missing from the uploaded signed report. Please upload the complete PDF.')
        ->and($findingReport->lgu_dromic_review_screenshots)->toHaveCount(1)
        ->and($findingReport->lgu_dromic_review_history)->toHaveCount(1)
        ->and(data_get($findingReport->lgu_dromic_review_screenshots, '0.name'))->toBe('missing-page.png');
    Storage::disk('local')->assertExists(data_get($findingReport->lgu_dromic_review_screenshots, '0.path'));
    $this->actingAs($owner)
        ->get("/lgu/dromic-sitrep/{$report->id}/validation-screenshot/report/0")
        ->assertOk()
        ->assertHeader('content-type', 'image/png');

    $this->actingAs($owner)
        ->post("/lgu/dromic-sitrep/{$report->id}/signed-copies", [
            'signed_report' => UploadedFile::fake()->create('complete-signed-report.pdf', 100, 'application/pdf'),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($reviewer)
        ->patch("/dromic/lgu-reports/{$report->id}/validation", [
            'validation_status' => 'validated_no_findings',
            'review_note' => 'Reviewed the encoded data and complete signed report.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $reviewedReport = $report->fresh();
    expect($reviewedReport->lgu_dromic_validation_status)->toBe('validated_no_findings')
        ->and($reviewedReport->lgu_dromic_reviewed_by)->toBe($reviewer->id)
        ->and($reviewedReport->lgu_dromic_review_note)->toBe('Reviewed the encoded data and complete signed report.')
        ->and($reviewedReport->lgu_dromic_review_screenshots)->toBeNull()
        ->and($reviewedReport->lgu_dromic_review_history)->toHaveCount(2)
        ->and(data_get($reviewedReport->lgu_dromic_review_history, '0.review_note'))->toBe('Page 2 is missing from the uploaded signed report. Please upload the complete PDF.')
        ->and(data_get($reviewedReport->lgu_dromic_review_history, '1.validation_status'))->toBe('validated_no_findings');
    $this->actingAs($owner)
        ->get("/lgu/dromic-sitrep/{$report->id}/validation-history-screenshot/report/0/0")
        ->assertOk()
        ->assertHeader('content-type', 'image/png');

    $this->actingAs($reviewer)
        ->get('/dromic/lgu-reports')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('reports.data.0.validation_status', 'validated_no_findings')
            ->where('reports.data.0.validation_note', 'Reviewed the encoded data and complete signed report.')
            ->where('reports.data.0.reviewer.name', $reviewer->name)
            ->where('reports.data.0.has_relief_request', false));

    $this->actingAs($owner)
        ->get('/lgu/dromic-sitrep')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requests.data.0.lgu_dromic_validation_status', 'validated_no_findings')
            ->where('requests.data.0.lgu_dromic_review_note', 'Reviewed the encoded data and complete signed report.')
            ->where('requests.data.0.lgu_dromic_reviewer.name', $reviewer->name));
});

it('allows validating an advance copy with no findings before the signed PDF is uploaded', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    $owner = User::where('email', 'superadmin@example.test')->firstOrFail();
    $reviewer = User::where('email', 'drims@example.test')->firstOrFail();

    $this->actingAs($owner)
        ->post('/lgu/dromic-sitrep', completeLguDromicPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $report = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();
    $this->actingAs($owner)->post("/lgu/dromic-sitrep/{$report->id}/submit")->assertRedirect();

    expect($report->fresh()->lgu_signed_report_path)->toBeNull();

    $this->actingAs($reviewer)
        ->post("/dromic/lgu-reports/{$report->id}/validation", [
            'validation_status' => 'validated_no_findings',
            'review_note' => 'Advance copy and encoded data are clean.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    $validatedAdvance = $report->fresh();
    expect($validatedAdvance->lgu_dromic_validation_status)->toBe('validated_no_findings')
        ->and(data_get($validatedAdvance->lgu_dromic_review_history, '0.validated_copy'))->toBe('advance')
        ->and($validatedAdvance->lgu_signed_report_path)->toBeNull();

    $this->actingAs($owner)
        ->post("/lgu/dromic-sitrep/{$report->id}/signed-copies", [
            'signed_report' => UploadedFile::fake()->create('signed-report.pdf', 100, 'application/pdf'),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $awaitingSignedReview = $report->fresh();
    expect($awaitingSignedReview->lgu_dromic_validation_status)->toBe('validated_no_findings')
        ->and($awaitingSignedReview->lgu_signed_report_path)->not->toBeNull()
        ->and(data_get($awaitingSignedReview->lgu_dromic_review_history, '0.validated_copy'))->toBe('advance');

    $this->actingAs($reviewer)
        ->post("/dromic/lgu-reports/{$report->id}/validation", [
            'validation_status' => 'needs_lgu_action',
            'review_note' => 'Signed PDF is incomplete. Please re-upload the full signed report.',
            'correction_scope' => 'document',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($report->fresh()->lgu_dromic_validation_status)->toBe('needs_lgu_action');

    $this->actingAs($owner)
        ->post("/lgu/dromic-sitrep/{$report->id}/signed-copies", [
            'signed_report' => UploadedFile::fake()->create('replacement-signed-report.pdf', 100, 'application/pdf'),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($reviewer)
        ->post("/dromic/lgu-reports/{$report->id}/validation", [
            'validation_status' => 'validated_no_findings',
            'review_note' => 'Signed PDF matches the validated advance copy.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $validatedSigned = $report->fresh();
    expect($validatedSigned->lgu_dromic_validation_status)->toBe('validated_no_findings')
        ->and(data_get($validatedSigned->lgu_dromic_review_history, '-1.validated_copy')
            ?? data_get(collect($validatedSigned->lgu_dromic_review_history)->last(), 'validated_copy'))->toBe('signed');
});

it('creates an auditable correction revision for encoded report findings and supports review transitions', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    $owner = User::where('email', 'superadmin@example.test')->firstOrFail();
    $reviewer = User::where('email', 'drims@example.test')->firstOrFail();

    $this->actingAs($owner)
        ->post('/lgu/dromic-sitrep', completeLguDromicPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $report = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();
    $this->actingAs($owner)->post("/lgu/dromic-sitrep/{$report->id}/submit")->assertRedirect();

    $this->actingAs($reviewer)
        ->patch("/dromic/lgu-reports/{$report->id}/validation", [
            'validation_status' => 'under_review',
            'review_note' => 'The report is currently being checked.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    expect($report->fresh()->lgu_dromic_validation_status)->toBe('under_review')
        ->and($report->fresh()->lgu_dromic_correction_scope)->toBeNull();

    $this->actingAs($reviewer)
        ->patch("/dromic/lgu-reports/{$report->id}/validation", [
            'validation_status' => 'needs_lgu_action',
            'correction_scope' => 'encoding',
            'review_note' => 'Correct the encoded population entries before resubmission.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($owner)
        ->post("/lgu/dromic-sitrep/{$report->id}/correction-draft", ['target' => 'report'])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('correction_draft_id');

    $correction = AssistanceRequest::query()
        ->where('lgu_correction_of_id', $report->id)
        ->where('lgu_correction_target', 'report')
        ->firstOrFail();

    expect($correction->lgu_report_status)->toBe('draft')
        ->and($correction->lgu_dromic_series_key)->toBe($report->lgu_dromic_series_key)
        ->and($correction->lgu_dromic_report_number)->toBe($report->lgu_dromic_report_number)
        ->and($correction->lgu_dromic_revision_number)->toBe(1)
        ->and($report->fresh()->lgu_dromic_revision_number)->toBe(0);

    $this->actingAs($owner)
        ->get('/lgu/dromic-sitrep?tab=reports&validation=needs_lgu_action')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('flash.correction_draft_id', $correction->id)
            ->where('correctionDraft.id', $correction->id)
            ->where('correctionDraft.lgu_correction_target', 'report'));

    $this->actingAs($reviewer)
        ->get('/dromic/lgu-reports?tab=reports')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('reports.data', 1)
            ->where('reports.data.0.id', $report->id));

    $this->actingAs($owner)
        ->post("/lgu/dromic-sitrep/{$report->id}/correction-draft", ['target' => 'report'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(AssistanceRequest::query()
        ->where('lgu_correction_of_id', $report->id)
        ->where('lgu_correction_target', 'report')
        ->count())->toBe(1);

    $this->actingAs($owner)
        ->patch("/lgu/dromic-sitrep/{$correction->id}", completeLguDromicPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    expect($correction->fresh()->lgu_report_status)->toBe('final')
        ->and($correction->fresh()->lgu_dromic_report_number)->toBe($report->lgu_dromic_report_number)
        ->and($correction->fresh()->lgu_dromic_revision_number)->toBe(1);

    $this->actingAs($owner)
        ->post("/lgu/dromic-sitrep/{$correction->id}/signed-copies", [
            'signed_report' => UploadedFile::fake()->create('corrected-signed-report.pdf', 100, 'application/pdf'),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $this->actingAs($owner)
        ->post("/lgu/dromic-sitrep/{$correction->id}/submit")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($reviewer)
        ->patch("/dromic/lgu-reports/{$correction->id}/validation", [
            'validation_status' => 'validated_no_findings',
            'review_note' => 'The corrected encoded entries and signed PDF now pass validation.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($correction->fresh()->lgu_dromic_validation_status)->toBe('validated_no_findings')
        ->and($report->fresh()->lgu_dromic_validation_status)->toBe('superseded')
        ->and($report->fresh()->lgu_dromic_correction_resolved_at)->not->toBeNull();

    $this->actingAs($owner)
        ->get('/lgu/dromic-sitrep?tab=reports')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requests.data', 1)
            ->where('requests.data.0.id', $correction->id)
            ->where('requests.data.0.lgu_dromic_revision_number', 1)
            ->has('requests.data.0.revision_history', 2));

    $this->actingAs($reviewer)
        ->get('/dromic/lgu-reports?tab=reports')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('reports.data', 1)
            ->where('reports.data.0.id', $correction->id)
            ->where('reports.data.0.lgu_dromic_revision_number', 1)
            ->missing('reports.data.0.revision_history'));
});

it('records report and request-letter view receipts for the originating LGU', function (): void {
    $this->seed(DatabaseSeeder::class);
    $owner = User::where('email', 'superadmin@example.test')->firstOrFail();
    $reviewer = User::where('email', 'drims@example.test')->firstOrFail();

    $this->actingAs($owner)
        ->post('/lgu/dromic-sitrep', completeLguDromicPayload())
        ->assertRedirect();
    $report = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();
    $this->actingAs($owner)->post("/lgu/dromic-sitrep/{$report->id}/submit")->assertRedirect();

    $this->actingAs($reviewer)
        ->patchJson("/lgu/dromic-sitrep/{$report->id}/document-viewed", ['kind' => 'report'])
        ->assertOk()
        ->assertJsonPath('seen_by', $reviewer->name)
        ->assertJsonPath('acked_by', $reviewer->name);

    $receipt = $report->fresh();
    expect($receipt->lgu_dromic_seen_at)->not->toBeNull()
        ->and($receipt->lgu_dromic_seen_by)->toBe($reviewer->id)
        ->and($receipt->lgu_dromic_acked_at)->not->toBeNull()
        ->and($receipt->lgu_dromic_acked_by)->toBe($reviewer->id)
        ->and($receipt->lgu_relief_seen_at)->toBeNull();
});

it('lets DRIMS view the advance-copy PDF and acknowledge receipt even after validation', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    $owner = User::where('email', 'superadmin@example.test')->firstOrFail();
    $drims = User::where('email', 'drims@example.test')->firstOrFail();

    $this->actingAs($owner)
        ->post('/lgu/dromic-sitrep', completeLguDromicPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $report = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();
    $this->actingAs($owner)->post("/lgu/dromic-sitrep/{$report->id}/submit")->assertRedirect();

    $this->actingAs($drims)
        ->get("/lgu/dromic-sitrep/{$report->id}/pdf?inline=1")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->actingAs($owner)->post("/lgu/dromic-sitrep/{$report->id}/signed-copies", [
        'signed_report' => UploadedFile::fake()->create('signed-report.pdf', 100, 'application/pdf'),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->actingAs($drims)
        ->get("/lgu/dromic-sitrep/{$report->id}/pdf?inline=1")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    $this->actingAs($drims)
        ->get("/lgu/dromic-sitrep/{$report->id}/signed-copy/report")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->actingAs($drims)->patch("/dromic/lgu-reports/{$report->id}/validation", [
        'validation_status' => 'validated_no_findings',
        'review_note' => 'Advance and signed copies are clean.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->actingAs($drims)
        ->patchJson("/lgu/dromic-sitrep/{$report->id}/document-viewed", ['kind' => 'report'])
        ->assertOk()
        ->assertJsonPath('acked_by', $drims->name);

    expect($report->fresh()->lgu_dromic_acked_at)->not->toBeNull()
        ->and($report->fresh()->lgu_dromic_acked_by)->toBe($drims->id)
        ->and($report->fresh()->lgu_dromic_validation_status)->toBe('validated_no_findings');
});

it('lets DRIMS view advance PDFs by role even when permission sync is missing', function (): void {
    $this->seed(DatabaseSeeder::class);
    $owner = User::where('email', 'superadmin@example.test')->firstOrFail();
    $drims = User::where('email', 'drims@example.test')->firstOrFail();

    $drimsRole = Role::findByName('DRIMS', 'web');
    $drimsRole->syncPermissions([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $drims = $drims->fresh();
    expect($drims->can('monitor requests'))->toBeFalse()
        ->and($drims->hasRole('DRIMS'))->toBeTrue();

    $this->actingAs($owner)
        ->post('/lgu/dromic-sitrep', completeLguDromicPayload())
        ->assertRedirect();
    $report = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();
    $this->actingAs($owner)->post("/lgu/dromic-sitrep/{$report->id}/submit")->assertRedirect();

    $this->actingAs($drims)
        ->get("/lgu/dromic-sitrep/{$report->id}/pdf?inline=1")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    $this->actingAs($drims)
        ->patchJson("/lgu/dromic-sitrep/{$report->id}/document-viewed", ['kind' => 'report'])
        ->assertOk()
        ->assertJsonPath('message', 'Report receipt acknowledged.');
});

it('separates DROMIC and relief document review permissions and locks each validated PDF', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    $owner = User::where('email', 'superadmin@example.test')->firstOrFail();
    $drims = User::where('email', 'drims@example.test')->firstOrFail();
    $drrs = User::where('email', 'drrs@example.test')->firstOrFail();
    $fniItem = FniLibraryItem::query()->create([
        'item_category' => 'Food',
        'item_name' => 'Family Food Pack',
        'brand_description' => 'Standard',
        'unit_of_measure' => 'pack',
        'is_active' => true,
    ]);

    $this->actingAs($owner)->post('/lgu/dromic-sitrep', completeLguDromicPayload([
        'has_relief_request' => true,
        'relief_requested' => '100 family food packs',
        'requested_fni_items' => [
            ['fni_library_item_id' => $fniItem->id, 'requested_quantity' => 100],
        ],
    ]))->assertRedirect()->assertSessionHasNoErrors();
    $report = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();
    $this->actingAs($owner)->post("/lgu/dromic-sitrep/{$report->id}/submit")->assertRedirect();
    $this->actingAs($owner)->post("/lgu/dromic-sitrep/{$report->id}/signed-copies", [
        'signed_report' => UploadedFile::fake()->create('signed-report.pdf', 100, 'application/pdf'),
        'signed_request' => UploadedFile::fake()->create('signed-request.pdf', 100, 'application/pdf'),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->actingAs($drims)->patch("/dromic/lgu-reports/{$report->id}/relief-validation", [
        'validation_status' => 'validated_no_findings',
        'review_note' => 'Complete signed relief request.',
    ])->assertForbidden();

    $this->actingAs($drrs)->patch("/dromic/lgu-reports/{$report->id}/relief-validation", [
        'validation_status' => 'validated_no_findings',
        'review_note' => 'The signed relief augmentation request is complete.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->actingAs($drims)->patch("/dromic/lgu-reports/{$report->id}/validation", [
        'validation_status' => 'validated_no_findings',
        'review_note' => 'The DROMIC report and its signed PDF are complete.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $report->refresh();
    expect($report->lgu_dromic_validation_status)->toBe('validated_no_findings')
        ->and($report->lgu_dromic_reviewed_by)->toBe($drims->id)
        ->and($report->lgu_dromic_review_history)->toHaveCount(1)
        ->and($report->lgu_relief_validation_status)->toBe('validated_no_findings')
        ->and($report->lgu_relief_reviewed_by)->toBe($drrs->id)
        ->and($report->lgu_relief_review_history)->toHaveCount(1);

    $this->actingAs($owner)->post("/lgu/dromic-sitrep/{$report->id}/signed-copies", [
        'signed_report' => UploadedFile::fake()->create('replacement-report.pdf', 100, 'application/pdf'),
    ])->assertStatus(422);
    $this->actingAs($owner)->post("/lgu/dromic-sitrep/{$report->id}/signed-copies", [
        'signed_request' => UploadedFile::fake()->create('replacement-request.pdf', 100, 'application/pdf'),
    ])->assertStatus(422);
});

it('lets LGU request amendment on a submitted report and DRIMS approve into a same-number correction draft', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    $owner = User::where('email', 'superadmin@example.test')->firstOrFail();
    $drims = User::where('email', 'drims@example.test')->firstOrFail();

    $this->actingAs($owner)
        ->post('/lgu/dromic-sitrep', completeLguDromicPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $report = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();
    expect($report->lgu_report_status)->toBe('final');

    $this->actingAs($owner)
        ->post("/lgu/dromic-sitrep/{$report->id}/submit")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($report->fresh()->lgu_report_status)->toBe('advance_submitted');

    $this->actingAs($owner)
        ->post("/lgu/dromic-sitrep/{$report->id}/amendment-request", [
            'reason' => 'Omitted outside EC families were validated after finalize and a next SitRep would be unreasonable within hours.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($report->fresh()->lgu_amendment_request_status)->toBe('requested')
        ->and($report->fresh()->lgu_amendment_requested_by)->toBe($owner->id);

    $this->actingAs($owner)
        ->post("/lgu/dromic-sitrep/{$report->id}/amendment-request", [
            'reason' => 'Duplicate amendment request should be rejected by the pending gate.',
        ])
        ->assertStatus(422);

    $this->actingAs($drims)
        ->post("/dromic/lgu-reports/{$report->id}/amendment-request", [
            'decision' => 'approve',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('correction_draft_id');

    $fresh = $report->fresh();
    $draft = AssistanceRequest::query()
        ->where('lgu_correction_of_id', $report->id)
        ->where('lgu_correction_target', 'report')
        ->firstOrFail();

    expect($fresh->lgu_amendment_request_status)->toBe('approved')
        ->and($fresh->lgu_dromic_validation_status)->toBe('needs_lgu_action')
        ->and($fresh->lgu_dromic_correction_scope)->toBe('encoding')
        ->and($draft->lgu_report_status)->toBe('draft')
        ->and($draft->lgu_dromic_report_number)->toBe($fresh->lgu_dromic_report_number)
        ->and((int) $draft->lgu_dromic_revision_number)->toBeGreaterThan((int) $fresh->lgu_dromic_revision_number);
});

it('keeps encoded FNI needs when a report amendment correction is finalized', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    $owner = User::where('email', 'superadmin@example.test')->firstOrFail();
    $drims = User::where('email', 'drims@example.test')->firstOrFail();
    $fniItem = FniLibraryItem::query()->create([
        'item_category' => 'Food',
        'item_name' => 'Family Food Pack',
        'brand_description' => 'Standard family food pack',
        'unit_of_measure' => 'box',
    ]);

    $this->actingAs($owner)
        ->post('/lgu/dromic-sitrep', completeLguDromicPayload([
            'has_relief_request' => false,
            'requested_fni_items' => [[
                'fni_library_item_id' => $fniItem->id,
                'requested_quantity' => 12,
            ]],
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $report = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();
    expect(data_get($report->lgu_dromic_payload, 'requested_fni_items.0.requested_quantity'))->toBe(12);

    $this->actingAs($owner)
        ->post("/lgu/dromic-sitrep/{$report->id}/submit")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($owner)
        ->post("/lgu/dromic-sitrep/{$report->id}/amendment-request", [
            'reason' => 'Need to encode omitted response actions while keeping the already encoded FNI needs.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($drims)
        ->post("/dromic/lgu-reports/{$report->id}/amendment-request", [
            'decision' => 'approve',
        ])
        ->assertRedirect()
        ->assertSessionHas('correction_draft_id');

    $draft = AssistanceRequest::query()
        ->where('lgu_correction_of_id', $report->id)
        ->where('lgu_correction_target', 'report')
        ->firstOrFail();

    expect(data_get($draft->lgu_dromic_payload, 'requested_fni_items.0.requested_quantity'))->toBe(12);

    $payload = completeLguDromicPayload([
        ...(array) $draft->lgu_dromic_payload,
        'has_relief_request' => false,
        'requested_fni_items' => [[
            'fni_library_item_id' => $fniItem->id,
            'requested_quantity' => 18,
        ]],
        'submission_status' => 'final',
    ]);

    $this->actingAs($owner)
        ->patch("/lgu/dromic-sitrep/{$draft->id}", $payload)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $draft->refresh();
    expect(data_get($draft->lgu_dromic_payload, 'requested_fni_items'))->toHaveCount(1)
        ->and((int) data_get($draft->lgu_dromic_payload, 'requested_fni_items.0.requested_quantity'))->toBe(18)
        ->and((bool) data_get($draft->lgu_dromic_payload, 'has_relief_request'))->toBeFalse()
        ->and($draft->lguDromicRequestedItems()->count())->toBe(1)
        ->and((int) $draft->lguDromicRequestedItems()->first()->requested_quantity)->toBe(18);
});

it('lets LGU request amendment after advance submit and DRIMS deny leaves encoding locked', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    $owner = User::where('email', 'superadmin@example.test')->firstOrFail();
    $drims = User::where('email', 'drims@example.test')->firstOrFail();

    $this->actingAs($owner)
        ->post('/lgu/dromic-sitrep', completeLguDromicPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $report = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();
    $this->actingAs($owner)->post("/lgu/dromic-sitrep/{$report->id}/submit")->assertRedirect();

    expect($report->fresh()->lgu_report_status)->toBe('advance_submitted');

    $this->actingAs($owner)
        ->post("/lgu/dromic-sitrep/{$report->id}/amendment-request", [
            'reason' => 'Additional damaged-house counts arrived after the advance copy was sent to DSWD.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($drims)
        ->post("/dromic/lgu-reports/{$report->id}/amendment-request", [
            'decision' => 'deny',
            'review_note' => 'Create the next sequential SitRep instead of amending this advance copy.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($report->fresh()->lgu_amendment_request_status)->toBe('denied')
        ->and(AssistanceRequest::query()->where('lgu_correction_of_id', $report->id)->count())->toBe(0);

    $this->actingAs($owner)
        ->patch("/lgu/dromic-sitrep/{$report->id}", completeLguDromicPayload())
        ->assertStatus(422);
});

it('blocks amendment requests after validated no findings and on drafts or correction drafts', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    $owner = User::where('email', 'superadmin@example.test')->firstOrFail();
    $drims = User::where('email', 'drims@example.test')->firstOrFail();

    $this->actingAs($owner)
        ->post('/lgu/dromic-sitrep', [...completeLguDromicPayload(), 'submission_status' => 'draft'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $draft = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();

    $this->actingAs($owner)
        ->post("/lgu/dromic-sitrep/{$draft->id}/amendment-request", [
            'reason' => 'Drafts should not be eligible for amendment permission requests.',
        ])
        ->assertStatus(422);

    $this->actingAs($owner)
        ->patch("/lgu/dromic-sitrep/{$draft->id}", completeLguDromicPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $report = $draft->fresh();
    $this->actingAs($owner)->post("/lgu/dromic-sitrep/{$report->id}/submit")->assertRedirect();

    $this->actingAs($drims)
        ->patch("/dromic/lgu-reports/{$report->id}/validation", [
            'validation_status' => 'validated_no_findings',
            'review_note' => 'Advance copy is complete and accurate.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($owner)
        ->post("/lgu/dromic-sitrep/{$report->id}/amendment-request", [
            'reason' => 'Validated reports should no longer accept amendment requests from the LGU.',
        ])
        ->assertStatus(422);
});

it('lets LGU request amendment on a relief request and DRRS approve into a request correction draft', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    $owner = User::where('email', 'superadmin@example.test')->firstOrFail();
    $drrs = User::where('email', 'drrs@example.test')->firstOrFail();
    $fniItem = FniLibraryItem::query()->create([
        'item_category' => 'Food',
        'item_name' => 'Family Food Pack',
        'brand_description' => 'Standard family food pack',
        'unit_of_measure' => 'box',
    ]);

    $this->actingAs($owner)
        ->post('/lgu/dromic-sitrep', completeLguDromicPayload([
            'has_relief_request' => true,
            'requested_fni_items' => [[
                'fni_library_item_id' => $fniItem->id,
                'requested_quantity' => 10,
            ]],
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $report = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();
    expect($report->lgu_relief_request_reference)->not->toBeNull();

    $this->actingAs($owner)
        ->post("/lgu/dromic-sitrep/{$report->id}/signed-copies", [
            'signed_report' => UploadedFile::fake()->create('signed-report.pdf', 100, 'application/pdf'),
            'signed_request' => UploadedFile::fake()->create('signed-request.pdf', 100, 'application/pdf'),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($owner)
        ->post("/lgu/dromic-sitrep/{$report->id}/submit")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($owner)
        ->post("/lgu/dromic-sitrep/{$report->id}/amendment-request", [
            'target' => 'request',
            'reason' => 'Omitted FNI quantities were confirmed after finalize and creating a new relief request letter is unreasonable.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($report->fresh()->lgu_amendment_request_status)->toBe('requested')
        ->and($report->fresh()->lgu_amendment_request_target)->toBe('request');

    $this->actingAs($owner)
        ->post("/lgu/dromic-sitrep/{$report->id}/amendment-request", [
            'target' => 'request',
            'reason' => 'Duplicate amendment request should be rejected by the pending gate.',
        ])
        ->assertStatus(422);

    $this->actingAs($drrs)
        ->post("/dromic/lgu-reports/{$report->id}/amendment-request", [
            'decision' => 'approve',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('correction_draft_id');

    $fresh = $report->fresh();
    $draft = AssistanceRequest::query()
        ->where('lgu_correction_of_id', $report->id)
        ->where('lgu_correction_target', 'request')
        ->firstOrFail();

    expect($fresh->lgu_amendment_request_status)->toBe('approved')
        ->and($fresh->lgu_relief_validation_status)->toBe('needs_lgu_action')
        ->and($fresh->lgu_relief_correction_scope)->toBe('both')
        ->and($draft->lgu_report_status)->toBe('draft')
        ->and($draft->lgu_correction_target)->toBe('request');
});

it('blocks LGU relief amendment when DRRS already returned the request for correction', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    $owner = User::where('email', 'superadmin@example.test')->firstOrFail();
    $drrs = User::where('email', 'drrs@example.test')->firstOrFail();
    $fniItem = FniLibraryItem::query()->create([
        'item_category' => 'Food',
        'item_name' => 'Family Food Pack',
        'brand_description' => 'Standard family food pack',
        'unit_of_measure' => 'box',
    ]);

    $this->actingAs($owner)
        ->post('/lgu/dromic-sitrep', completeLguDromicPayload([
            'has_relief_request' => true,
            'requested_fni_items' => [[
                'fni_library_item_id' => $fniItem->id,
                'requested_quantity' => 8,
            ]],
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $report = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();

    $this->actingAs($owner)
        ->post("/lgu/dromic-sitrep/{$report->id}/signed-copies", [
            'signed_report' => UploadedFile::fake()->create('signed-report.pdf', 100, 'application/pdf'),
            'signed_request' => UploadedFile::fake()->create('signed-request.pdf', 100, 'application/pdf'),
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($owner)
        ->post("/lgu/dromic-sitrep/{$report->id}/submit")
        ->assertSessionHasNoErrors();

    $this->actingAs($drrs)
        ->patch("/dromic/lgu-reports/{$report->id}/relief-validation", [
            'validation_status' => 'needs_lgu_action',
            'review_note' => 'Requested quantities do not match the attached request letter narrative.',
            'correction_scope' => 'both',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($owner)
        ->post("/lgu/dromic-sitrep/{$report->id}/amendment-request", [
            'target' => 'request',
            'reason' => 'Should not request amendment when DRRS already returned the letter for correction.',
        ])
        ->assertStatus(422);
});

it('blocks LGU report amendment when DSWD already returned the report for correction', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    $owner = User::where('email', 'superadmin@example.test')->firstOrFail();
    $drims = User::where('email', 'drims@example.test')->firstOrFail();

    $this->actingAs($owner)
        ->post('/lgu/dromic-sitrep', completeLguDromicPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $report = AssistanceRequest::where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();
    $this->actingAs($owner)->post("/lgu/dromic-sitrep/{$report->id}/submit")->assertRedirect();

    $this->actingAs($drims)
        ->patch("/dromic/lgu-reports/{$report->id}/validation", [
            'validation_status' => 'needs_lgu_action',
            'review_note' => 'Encoded families are incomplete and must be corrected.',
            'correction_scope' => 'encoding',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($owner)
        ->post("/lgu/dromic-sitrep/{$report->id}/amendment-request", [
            'target' => 'report',
            'reason' => 'Should not request amendment when DSWD already returned the report for correction.',
        ])
        ->assertStatus(422);
});
