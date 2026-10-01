<?php

use App\Models\AssistanceRequest;
use App\Models\DromicReport;
use App\Models\Incident;
use App\Models\User;
use App\Services\DswdDromicConsolidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function dswdSource(array $attributes = []): AssistanceRequest
{
    return AssistanceRequest::forceCreate(array_replace([
        'reference_number' => 'LGU-'.uniqid(), 'requesting_agency' => 'LGU Test', 'requester' => 'Reporter', 'date_requested' => '2026-09-01',
        'province' => 'Surigao del Norte', 'municipality' => 'Surigao City',
        'submission_type' => 'lgu_dromic_relief_request', 'lgu_report_status' => 'submitted',
        'lgu_submitted_to_dswd_at' => '2026-09-01 10:00:00', 'lgu_dromic_series_key' => 'fire-test',
        'lgu_dromic_report_number' => 1, 'lgu_dromic_revision_number' => 0,
        'lgu_dromic_validation_status' => 'validated_no_findings',
        'lgu_dromic_payload' => [
            'incident_name' => 'Fire incident', 'narrative' => 'A fire affected Washington.',
            'area_rows' => [['area' => 'Washington', 'affected_families' => 5, 'affected_persons' => 20,
                'outside_ec_included' => true, 'outside_ec_families_cum' => 2, 'outside_ec_families_now' => 0,
                'outside_ec_persons_cum' => 8, 'outside_ec_persons_now' => 0,
                'damaged_houses_included' => true, 'damaged_houses_totally' => 1, 'damaged_houses_partially' => 2]],
            'evacuation_center_rows' => [['evacuation_center' => 'School', 'families_cum' => 3, 'families_now' => 1, 'persons_cum' => 12, 'persons_now' => 4]],
            'assistance_rows' => [['source' => 'DSWD', 'quantity' => 5, 'cost_per_unit' => 750], ['source' => 'City/Municipal LGU', 'quantity' => 2, 'cost_per_unit' => 100]],
            'response_action_rows' => [['acted_by_office' => 'BFP', 'action_intervention' => 'Extinguished the fire.']],
        ],
    ], $attributes));
}

function dswdUser(): User
{
    Role::findOrCreate('DRIMS', 'web');
    $user = User::forceCreate(['name' => 'Regional Reporter', 'email' => uniqid().'@example.test', 'password' => bcrypt('test-password'), 'access_status' => 'approved']);
    $user->assignRole('DRIMS');

    return $user;
}

function dswdInput(array $ids): array
{
    $versions = AssistanceRequest::whereIn('id', $ids)->get()->mapWithKeys(fn ($r) => [$r->id => app(DswdDromicConsolidator::class)->source($r)['version']])->all();

    return ['source_ids' => $ids, 'source_versions' => $versions, 'title' => 'Fire in Caraga', 'report_type' => 'first_final', 'as_of' => '2026-09-08T12:00', 'overview' => 'The LGU responded to the fire.', 'overlap_reviewed' => true, 'relationship' => 'Related fire incidents in the reporting area.'];
}

it('consolidates geography, cumulative and current displacement and provided assistance', function () {
    $service = app(DswdDromicConsolidator::class);
    $one = dswdSource();
    $two = dswdSource(['province' => 'Agusan del Norte', 'municipality' => 'Cabadbaran', 'lgu_dromic_series_key' => 'second-fire']);
    $result = $service->consolidate(collect([$service->source($one), $service->source($two)]));
    expect($result['tables']['affected']['rows'][0]['values'])->toBe(['barangays' => 2, 'families' => 10.0, 'persons' => 40.0]);
    expect($result['tables']['displaced']['rows'][0]['values'])->toBe(['families_cum' => 10.0, 'families_now' => 2.0, 'persons_cum' => 40.0, 'persons_now' => 8.0]);
    expect($result['tables']['assistance']['rows'][0]['values']['total'])->toBe(7900.0);
    expect($result['tables']['houses']['rows'][0]['values']['total'])->toBe(6.0);
    $barangayRows = collect($result['tables']['affected']['rows'])->where('level', 'barangay')->values();
    expect($barangayRows)->toHaveCount(2)
        ->and($barangayRows->pluck('label')->all())->toBe(['Washington', 'Washington'])
        ->and($barangayRows->pluck('values.families')->all())->toBe([5.0, 5.0])
        ->and($barangayRows->pluck('values.persons')->all())->toBe([20.0, 20.0]);
    expect($result['sources'][0]['actions'][0]['office'])->toBe('BFP');
});

it('normalizes all-uppercase LGU location names for display', function () {
    $service = app(DswdDromicConsolidator::class);
    expect($service->displayPlace('TUBOD'))->toBe('Tubod');
    expect($service->displayPlace('SURIGAO DEL NORTE'))->toBe('Surigao del Norte');
    expect($service->displayPlace('City of Surigao'))->toBe('City of Surigao');
});

it('integrates sex-age and sectoral IDP distributions from completed evacuation center data', function () {
    $service = app(DswdDromicConsolidator::class);
    $source = dswdSource();
    $payload = $source->lgu_dromic_payload;
    $payload['evacuation_center_rows'][0]['disaggregation_completed'] = true;
    $payload['evacuation_center_rows'][0]['disaggregation'] = [
        'age_sex' => ['adult' => ['male_cum' => 4, 'male_now' => 1, 'female_cum' => 5, 'female_now' => 2]],
        'sectoral' => ['pwds' => ['male_cum' => 1, 'male_now' => 0, 'female_cum' => 2, 'female_now' => 1]],
    ];
    $source->lgu_dromic_payload = $payload;
    $snapshot = $service->consolidate(collect([$service->source($source)]));
    expect($snapshot['tables']['age_sex']['rows'][5]['values'])->toMatchArray(['male_cum' => 4.0, 'male_now' => 1.0, 'female_cum' => 5.0, 'female_now' => 2.0, 'total_cum' => 9.0, 'total_now' => 3.0]);
    expect($snapshot['tables']['sectoral']['rows'][0]['values']['total_cum'])->toBe(3.0);
});

it('distinguishes missing data from not applicable and deduplicates barangays within a locality', function () {
    $service = app(DswdDromicConsolidator::class);
    $one = dswdSource();
    $p = $one->lgu_dromic_payload;
    $p['not_applicable_sections'] = ['inside_ec'];
    unset($p['area_rows'][0]['outside_ec_persons_now']);
    $one->lgu_dromic_payload = $p;
    $s = $service->source($one);
    expect($s['metrics']['inside']['persons_now'])->toBe(0.0);
    expect($s['metrics']['displaced']['persons_now'])->toBeNull();
    expect($service->consolidate(collect([$s, $s]))['tables']['affected']['rows'][0]['values']['barangays'])->toBe(1);
});

it('rechecks source eligibility and rejects cumulative duplicate selections', function () {
    $user = dswdUser();
    $one = dswdSource();
    $two = dswdSource(['lgu_dromic_report_number' => 2]);
    $this->actingAs($user)->post('/dromic', dswdInput([$one->id, $two->id]))->assertSessionHasErrors('source_ids');
    $two->update(['lgu_dromic_validation_status' => 'needs_lgu_action']);
    $this->post('/dromic', dswdInput([$two->id]))->assertSessionHasErrors('source_ids');
    $this->post('/dromic', dswdInput([$one->id]))->assertSessionHasErrors('source_ids');
    $two->update(['lgu_dromic_validation_status' => 'validated_no_findings']);
    $this->post('/dromic', [...dswdInput([$two->id]), 'as_of' => '2026-08-01'])->assertSessionHasErrors('as_of');
    expect(DromicReport::count())->toBe(0);
});

it('saves a stable snapshot and exports its narrative and workbook', function () {
    $user = dswdUser();
    $one = dswdSource();
    $this->actingAs($user)->post('/dromic', dswdInput([$one->id]))->assertSessionHasNoErrors()->assertRedirect('/dromic');
    $report = DromicReport::firstOrFail();
    expect($report->date_released)->toBeNull();
    $one->update(['lgu_dromic_payload' => []]);
    expect($report->fresh()->consolidation['tables']['affected']['rows'][0]['values']['families'])->toEqual(5);
    $this->get('/dromic')->assertOk();
    $this->get('/dromic/reports/'.$report->id.'/pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
    $response = $this->get('/dromic/reports/'.$report->id.'/xlsx')->assertOk();
    expect(substr($response->streamedContent(), 0, 2))->toBe('PK');
    $scoped = dswdUser();
    $scoped->forceFill(['aor_cities_municipalities' => ['unrelated-city']])->save();
    $this->actingAs($scoped)->get('/dromic/reports/'.$report->id.'/pdf')->assertForbidden();
});

it('saves editable DSWD response rows and reviewed assistance and CCCM IDPP figures', function () {
    $user = dswdUser();
    $source = dswdSource();
    $input = dswdInput([$source->id]);
    $input['response_sections'] = [
        'idpp' => [[
            'date_from' => '2026-09-02',
            'date_to' => '2026-09-04',
            'bullets' => ['Provided psychological first aid to affected families.'],
        ]],
        'cccm' => [],
        'ect' => [],
        'other' => [[
            'date_from' => '2026-09-05',
            'date_to' => null,
            'bullets' => ['Coordinated the delivery schedule.', 'Monitored the distribution.'],
        ]],
    ];
    $input['section_photos'] = [
        'section_i' => ['data_url' => 'data:image/jpeg;base64,'.base64_encode('test-image'), 'side' => 'right', 'width' => 55, 'offset_y' => 8, 'aspect_ratio' => 1.5, 'alt' => 'Incident map'],
        'section_ii' => null,
    ];
    $input['review_edits'] = [
        'assistance' => ['reason' => 'Verified against the LGU review table.', 'rows' => [['index' => 2, 'values' => ['lgu' => 500, 'ngo' => 100, 'others' => 50]]]],
        'cccm' => ['reason' => 'Verified against the DSWD intervention record.', 'rows' => [['index' => 2, 'values' => ['pfa' => 5, 'command_cum' => 1, 'command_now' => 1]]]],
    ];

    $this->actingAs($user)->post('/dromic', $input)->assertSessionHasNoErrors()->assertRedirect('/dromic');
    $snapshot = DromicReport::firstOrFail()->consolidation;

    expect($snapshot['metadata']['response_sections']['idpp'][0]['bullets'][0])->toBe('Provided psychological first aid to affected families.')
        ->and($snapshot['metadata']['response_sections']['other'][0]['date_from'])->toBe('2026-09-05')
        ->and($snapshot['metadata']['section_photos']['section_i']['width'])->toEqual(55)
        ->and($snapshot['tables']['assistance']['rows'][2]['values']['lgu'])->toEqual(500)
        ->and($snapshot['tables']['assistance']['rows'][2]['values']['total'])->toEqual(650)
        ->and($snapshot['cccm']['rows'][2]['values']['pfa'])->toEqual(5)
        ->and($snapshot['cccm']['rows'][0]['values']['pfa'])->toEqual(5);
});

it('does not expose the regional workspace to an unauthorised user', function () {
    $user = dswdUser();
    $user->syncRoles([]);
    $this->actingAs($user)->get('/dromic')->assertForbidden();
});

it('rejects stale source data and excludes superseded revisions and standalone requests', function () {
    $user = dswdUser();
    $one = dswdSource();
    $input = dswdInput([$one->id]);
    $p = $one->lgu_dromic_payload;
    $p['area_rows'][0]['affected_families'] = 7;
    $one->update(['lgu_dromic_payload' => $p]);
    $this->actingAs($user)->post('/dromic', $input)->assertSessionHasErrors('source_ids');
    $newer = dswdSource(['lgu_dromic_revision_number' => 1]);
    dswdSource(['lgu_dromic_series_key' => 'standalone', 'lgu_dromic_payload' => ['standalone_relief_request' => true]]);
    dswdSource(['lgu_dromic_series_key' => 'request-correction', 'lgu_correction_target' => 'request']);
    expect(app(DswdDromicConsolidator::class)->eligible($user)->pluck('id')->all())->toBe([$newer->id]);
});

it('requires a relationship for multiple incidents and a number for progress reports', function () {
    $user = dswdUser();
    $one = dswdSource();
    $two = dswdSource(['lgu_dromic_series_key' => 'another-incident']);
    $this->actingAs($user)->post('/dromic', [...dswdInput([$one->id, $two->id]), 'relationship' => ''])->assertSessionHasErrors('relationship');
    $this->post('/dromic', [...dswdInput([$one->id]), 'report_type' => 'progress'])->assertSessionHasErrors('progress_number');
});

it('uses only the latest reporting period and its latest submitted revision without adding old totals', function () {
    $user = dswdUser();
    $initial = dswdSource();
    $oldInput = dswdInput([$initial->id]);
    dswdSource(['lgu_dromic_report_number' => 2]);
    $latest = dswdSource(['lgu_dromic_report_number' => 3, 'lgu_dromic_revision_number' => 1, 'lgu_dromic_report_classification' => 'terminal']);
    // A late correction of an older period does not displace the terminal report.
    dswdSource(['lgu_dromic_report_number' => 1, 'lgu_dromic_revision_number' => 5, 'lgu_submitted_to_dswd_at' => '2026-09-07 10:00:00']);
    dswdSource(['lgu_dromic_report_number' => 3, 'lgu_dromic_revision_number' => 0]);
    // An unfinished future report is not yet a submitted source.
    dswdSource(['lgu_dromic_report_number' => 4, 'lgu_report_status' => 'draft', 'lgu_submitted_to_dswd_at' => null]);

    $service = app(DswdDromicConsolidator::class);
    expect($service->eligible($user)->pluck('id')->all())->toBe([$latest->id]);
    $this->actingAs($user)->get('/dromic')->assertInertia(fn ($page) => $page
        ->component('Dashboard/Dromic')->has('eligibleReports', 1)->where('eligibleReports.0.id', $latest->id));
    $this->post('/dromic', $oldInput)->assertSessionHasErrors('source_ids');
    $this->post('/dromic', dswdInput([$latest->id]))->assertSessionHasNoErrors();
    expect(DromicReport::firstOrFail()->consolidation['tables']['affected']['rows'][0]['values']['families'])->toEqual(5);
});

it('does not revive earlier validated reports while the newest report awaits review or correction', function () {
    $user = dswdUser();
    $old = dswdSource();
    $latest = dswdSource(['lgu_dromic_report_number' => 2, 'lgu_report_status' => 'advance_submitted', 'lgu_dromic_validation_status' => 'pending_review']);
    $service = app(DswdDromicConsolidator::class);
    expect($service->eligible($user)->pluck('id')->all())->toBe([$latest->id]);
    expect($service->eligible($user)->where('lgu_dromic_validation_status', 'validated_no_findings'))->toHaveCount(0);
    $latest->update(['lgu_dromic_validation_status' => 'needs_lgu_action']);
    expect($service->eligible($user))->toHaveCount(0);
    $this->actingAs($user)->post('/dromic', dswdInput([$old->id]))->assertSessionHasErrors('source_ids');
});

it('retains each LGUs latest report for a shared incident including legacy reports without series keys', function () {
    $user = dswdUser();
    $incident = Incident::create(['name' => 'Regional flooding']);
    $identity = ['incident_id' => $incident->id, 'lgu_dromic_series_key' => null];
    dswdSource($identity);
    $latest = dswdSource([...$identity, 'lgu_dromic_report_number' => 2]);
    $otherLgu = dswdSource([...$identity, 'municipality' => 'Dapa']);
    $separateIncident = dswdSource(['lgu_dromic_series_key' => 'unrelated-fire']);
    $ids = app(DswdDromicConsolidator::class)->eligible($user)->pluck('id')->all();
    expect($ids)->toHaveCount(3)->toContain($latest->id, $otherLgu->id, $separateIncident->id);
});

it('keeps saved DSWD DROMIC drafts read-only after LGU validation', function () {
    $user = dswdUser();
    $source = dswdSource();
    $this->actingAs($user)->post('/dromic', dswdInput([$source->id]))->assertSessionHasNoErrors();
    $report = DromicReport::firstOrFail();
    $this->getJson('/dromic/reports/'.$report->id)->assertOk()->assertJsonMissing(['version', 'history']);
    $this->actingAs($user)->patchJson('/dromic/reports/'.$report->id.'/sections', [
        'section' => 'affected', 'reason' => 'Attempted correction', 'values' => [],
    ])->assertNotFound();
});
