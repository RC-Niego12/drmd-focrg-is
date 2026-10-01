<?php

use App\Models\PreparednessBriefingIntro;
use App\Models\PreparednessActionPage;
use App\Models\PreparednessQrtCoverageArea;
use App\Models\PreparednessResponseAsset;
use App\Models\PreparednessReport;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function preparednessUser(string $role, string $email): User
{
    $user = User::create([
        'name' => $role.' Preparedness Tester',
        'email' => $email,
        'password' => bcrypt('password'),
        'is_active' => true,
        'access_status' => 'approved',
        'access_approved_at' => now(),
    ]);
    $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));

    return $user;
}

function preparednessDraft(User $user, ?string $title = null): PreparednessReport
{
    test()->actingAs($user)->post(route('preparedness-for-response.reports.store'), ['reporting_schedule' => now()->addDay()->format('Y-m-d H:i:s')])->assertRedirect();
    $report = PreparednessReport::query()->latest('id')->firstOrFail();
    if ($title !== null) $report->update(['title' => $title]);
    return $report->fresh();
}

it('lets DRIMS replace and reorder preparedness section rows', function (): void {
    $drims = preparednessUser('DRIMS', 'drims-preparedness@example.test');
    $report = preparednessDraft($drims);
    $first = PreparednessQrtCoverageArea::query()->where('preparedness_report_id', $report->id)->orderBy('sort_order')->firstOrFail();

    $this->actingAs($drims)->patch(route('preparedness-for-response.data.update', [$report, 'qrt-coverage']), [
        'rows' => [
            ['id' => $first->id, 'area' => 'Regional QRT / Field Office', 'coverage_type' => 'Regional', 'members' => 205],
            ['area' => 'New Coverage Area', 'coverage_type' => 'Provincial / City / Municipal', 'members' => 12],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(PreparednessQrtCoverageArea::where('preparedness_report_id', $report->id)->count())->toBe(2)
        ->and(PreparednessQrtCoverageArea::withTrashed()->where('preparedness_report_id', $report->id)->whereNotNull('deleted_at')->count())->toBe(5)
        ->and($first->fresh()->members)->toBe(205)
        ->and(PreparednessQrtCoverageArea::where('area', 'New Coverage Area')->value('sort_order'))->toBe(2);
});

it('rejects non-DRIMS preparedness data edits', function (): void {
    $lgu = preparednessUser('LGU', 'lgu-preparedness@example.test');
    $report = PreparednessReport::query()->create(['title' => 'Unauthorized Test', 'status' => 'draft']);

    $this->actingAs($lgu)->patch(route('preparedness-for-response.data.update', [$report, 'response-assets']), [
        'rows' => PreparednessResponseAsset::query()->get()->toArray(),
    ])->assertForbidden();
});

it('locks existing asset images and uploads images for newly added assets', function (): void {
    Storage::fake('public');
    $drims = preparednessUser('DRIMS', 'drims-assets@example.test');
    $report = preparednessDraft($drims);
    $existing = PreparednessResponseAsset::query()->where('preparedness_report_id', $report->id)->firstOrFail();
    $originalImage = $existing->image_path;

    $this->actingAs($drims)->post(route('preparedness-for-response.data.update', [$report, 'response-assets']), [
        '_method' => 'PATCH',
        'rows' => [
            [
                'id' => $existing->id,
                'label' => $existing->label,
                'quantity' => 2,
                'status' => 'Deployed',
                'image_path' => '/images/tampered.png',
            ],
            [
                'label' => 'New Response Asset',
                'quantity' => 1,
                'status' => 'On Standby',
                'image_path' => '/images/untrusted.png',
                'image_file' => UploadedFile::fake()->image('new-asset.png'),
            ],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($existing->fresh()->image_path)->toBe($originalImage)
        ->and($existing->fresh()->status)->toBe('Deployed');

    $newAsset = PreparednessResponseAsset::query()->where('preparedness_report_id', $report->id)->where('label', 'New Response Asset')->firstOrFail();
    expect($newAsset->image_path)->toStartWith('/storage/preparedness-assets/');
    Storage::disk('public')->assertExists(str_replace('/storage/', '', $newAsset->image_path));
});

it('serves updated preparedness rows for recalculating consolidated page data', function (): void {
    $drims = preparednessUser('DRIMS', 'drims-consolidated@example.test');
    $report = preparednessDraft($drims);
    $rows = PreparednessQrtCoverageArea::query()->where('preparedness_report_id', $report->id)->orderBy('sort_order')->get();

    $this->actingAs($drims)->patch(route('preparedness-for-response.data.update', [$report, 'qrt-coverage']), [
        'rows' => $rows->map(fn (PreparednessQrtCoverageArea $row): array => [
            'id' => $row->id,
            'area' => $row->area,
            'coverage_type' => $row->coverage_type,
            'members' => $row->area === 'Regional QRT / Field Office' ? 250 : $row->members,
        ])->all(),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->actingAs($drims)->get(route('preparedness-for-response.reports.show', $report))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('PreparednessForResponse/Index')
            ->where('qrtCoverageAreas.0.members', 250)
            ->where('qrtCoverageAreas', fn ($updatedRows): bool => collect($updatedRows)->sum('members') === 1354));
});

it('excludes inactive warehouses from preparedness report data', function (): void {
    $drims = preparednessUser('DRIMS', 'drims-active-warehouses@example.test');
    $report = PreparednessReport::query()->create([
        'title' => 'Active Warehouses Only',
        'status' => 'draft',
        'reporting_as_of' => now()->addHour(),
        'revision_deadline' => now()->addHour(),
        'created_by' => $drims->id,
    ]);
    Warehouse::query()->create([
        'name' => 'Active Preparedness Warehouse',
        'province' => 'Agusan Del Norte',
        'municipality' => 'Butuan City',
        'status' => 'active',
        'ffp_capacity' => 120,
    ]);
    Warehouse::query()->create([
        'name' => 'Inactive Preparedness Warehouse',
        'province' => 'Agusan Del Norte',
        'municipality' => 'Cabadbaran City',
        'status' => 'inactive',
        'ffp_capacity' => 880,
    ]);

    $this->actingAs($drims)->get(route('preparedness-for-response.reports.show', $report))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.active_warehouses', 1)
            ->where('summary.total_warehouses', 1)
            ->has('warehouseRows', 1)
            ->where('warehouseRows.0.warehouse', 'Agusan Del Norte, Active Preparedness Warehouse')
            ->where('provinceRows.0.warehouses', 1)
            ->where('provinceRows.0.ffp_warehouses', 1)
            ->where('provinceRows.0.capacity', 120));
});

it('persists challenges and recommendations only in the selected report', function (): void {
    $drims = preparednessUser('DRIMS', 'drims-challenges@example.test');
    $report = preparednessDraft($drims);
    $other = preparednessDraft($drims);
    $intro = PreparednessBriefingIntro::where('preparedness_report_id', $report->id)->firstOrFail();
    $content = [...$intro->content, 'challenge_1' => 'Additional staffing needed.', 'recommendation_1' => 'Assign additional response staff.'];

    $this->actingAs($drims)->post(route('preparedness-for-response.data.update', [$report, 'briefing-intro']), [
        '_method' => 'PATCH', 'content' => $content,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->actingAs($drims)->get(route('preparedness-for-response.reports.show', $report))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('briefingIntro.content.challenge_1', 'Additional staffing needed.')
            ->where('briefingIntro.content.recommendation_1', 'Assign additional response staff.'));
    expect(PreparednessBriefingIntro::where('preparedness_report_id', $other->id)->firstOrFail()->content)
        ->not->toHaveKey('challenge_1');

    $report->update(['status' => 'finalized']);
    $this->actingAs($drims)->patch(route('preparedness-for-response.data.update', [$report, 'briefing-intro']), ['content' => $content])
        ->assertStatus(422);
});

it('saves additional challenges pages with independent column rows', function (): void {
    $drims = preparednessUser('DRIMS', 'drims-challenge-pages@example.test');
    $report = preparednessDraft($drims);
    $intro = PreparednessBriefingIntro::where('preparedness_report_id', $report->id)->firstOrFail();
    $content = [...$intro->content,
        'challenge_page_count' => '2',
        'challenge_page_0_challenges_count' => '1',
        'challenge_page_0_challenges_0' => 'First challenge',
        'challenge_page_0_recommendations_count' => '0',
        'challenge_page_1_challenges_count' => '1',
        'challenge_page_1_challenges_0' => 'Additional challenge',
        'challenge_page_1_recommendations_count' => '2',
        'challenge_page_1_recommendations_0' => 'First recommendation',
        'challenge_page_1_recommendations_1' => 'Second recommendation',
    ];
    $this->actingAs($drims)->patch(route('preparedness-for-response.data.update', [$report, 'briefing-intro']), ['content' => $content])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($drims)->get(route('preparedness-for-response.reports.show', $report))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('briefingIntro.content.challenge_page_count', '2')
            ->where('briefingIntro.content.challenge_page_0_recommendations_count', '0')
            ->where('briefingIntro.content.challenge_page_1_recommendations_1', 'Second recommendation'));

    $content['challenge_page_count'] = '1';
    $this->actingAs($drims)->patch(route('preparedness-for-response.data.update', [$report, 'briefing-intro']), ['content' => $content])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($intro->fresh()->content['challenge_page_count'])->toBe('1');
});

it('summarizes all identified EC statuses and selects only geotagged site photos', function (): void {
    $drims = preparednessUser('DRIMS', 'drims-ec-summary@example.test');
    $report = preparednessDraft($drims);
    $base = ['province' => 'Surigao del Norte', 'municipality' => 'Alegria', 'lat' => 9.5, 'lng' => 125.5,
        'photo_source' => 'lgu_geotag_sheet', 'photo_url' => 'https://drive.google.com/uc?id=test-ec-summary'];
    $centers = [
        [...$base, 'id' => 'p', 'name' => 'Permanent EC', 'availability' => 'Permanent'],
        [...$base, 'id' => 't', 'name' => 'School EC', 'availability' => 'Temporary', 'ec_type' => 'School'],
        [...$base, 'id' => 'n', 'name' => 'Hall EC', 'availability' => 'Temporary', 'ec_type' => 'Hall', 'lat' => null],
        ['id' => 'u', 'name' => 'Unclassified EC', 'availability' => ''],
    ];
    $this->mock(\App\Services\EvacuationCenterInventoryService::class)
        ->shouldReceive('inventory')->andReturn(['centers' => $centers]);
    $this->actingAs($drims)->get(route('preparedness-for-response.reports.show', $report))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('evacuationCenterSummary.total_identified', 4)
            ->where('evacuationCenterSummary.permanent_centers', 1)
            ->where('evacuationCenterSummary.temporary_centers', 2)
            ->where('evacuationCenterSummary.permanent_percentage', 25)
            ->where('evacuationCenterSummary.temporary_percentage', 50)
            ->where('evacuationCenterSummary.unclassified_centers', 1)
            ->where('evacuationCenterSummary.temporary_school_camps', 1)
            ->has('evacuationCenterSummary.selected_photos', 1));
});

it('selects two distinct permanent geotagged ECs per requested municipality', function (): void {
    $centers = collect();
    foreach (['Mainit', 'Alegria', 'Sison', 'Other LGU'] as $municipality) {
        foreach (range(1, 3) as $index) {
            $centers->push(['id' => $municipality.$index, 'name' => 'EC '.$index,
                'municipality' => $municipality, 'availability' => 'Permanent',
                'lat' => 9.5, 'lng' => 125.5, 'photo_source' => 'lgu_geotag_sheet',
                'photo_url' => 'https://drive.google.com/uc?id=photo-'.$index]);
        }
        $centers->push([...$centers->last(), 'id' => $municipality.'-temporary', 'availability' => 'Temporary']);
    }
    $photos = collect(app(\App\Services\PreparednessEvacuationPhotos::class)->selected($centers));
    expect($photos)->toHaveCount(6)
        ->and($photos->pluck('id')->unique())->toHaveCount(6)
        ->and($photos->pluck('status')->unique()->all())->toBe(['Permanent']);
    foreach (['Mainit', 'Alegria', 'Sison'] as $municipality) {
        expect($photos->where('municipality', $municipality))->toHaveCount(2);
    }
    expect($photos->where('municipality', 'Other LGU'))->toBeEmpty();
});

it('serves and caches inventory site photos from the same origin for PPT export', function (): void {
    $drims = preparednessUser('DRIMS', 'drims-ec-photo@example.test');
    $this->mock(\App\Services\EvacuationCenterInventoryService::class)->shouldReceive('inventory')->andReturn([
        'centers' => [['id' => 'test-center', 'photo_url' => 'https://drive.google.com/uc?id=test-ec-proxy']],
    ]);
    \Illuminate\Support\Facades\Cache::forget('preparedness-ec-photo-test-ec-proxy');
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a2ioAAAAASUVORK5CYII=');
    \Illuminate\Support\Facades\Http::fake(['https://lh3.googleusercontent.com/d/test-ec-proxy=w1200' => \Illuminate\Support\Facades\Http::response($png, 200, ['Content-Type' => 'image/png'])]);
    for ($i = 0; $i < 2; $i++) {
        $this->actingAs($drims)->get(route('preparedness-for-response.ec-photo', ['center' => 'test-center']))
            ->assertOk()->assertHeader('Content-Type', 'image/png')->assertContent($png);
    }
    $this->actingAs($drims)->get(route('preparedness-for-response.ec-photo', ['center' => 'unknown']))->assertNotFound();
    \Illuminate\Support\Facades\Http::assertSentCount(1);
});

it('updates the four introductory briefing pages and synopsis image', function (): void {
    Storage::fake('public');
    $drims = preparednessUser('DRIMS', 'drims-intro@example.test');
    $report = preparednessDraft($drims);
    $intro = PreparednessBriefingIntro::query()->where('preparedness_report_id', $report->id)->firstOrFail();
    $content = $intro->content;
    $content['title_event'] = 'Updated Regional Weather Event';
    $content['divider_as_of'] = 'AS OF 22 AUGUST 2026, 6:00 AM';

    $this->actingAs($drims)->post(route('preparedness-for-response.data.update', [$report, 'briefing-intro']), [
        '_method' => 'PATCH',
        'content' => $content,
        'synopsis_image' => UploadedFile::fake()->image('synopsis.png', 1200, 800),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $intro->refresh();
    expect($intro->content['title_event'])->toBe('Updated Regional Weather Event')
        ->and($intro->content['divider_as_of'])->toBe('AS OF 22 AUGUST 2026, 6:00 AM')
        ->and($intro->synopsis_image_path)->toStartWith('/storage/preparedness-intro/');
    Storage::disk('public')->assertExists(str_replace('/storage/', '', $intro->synopsis_image_path));
});

it('adds and updates actions taken pages with an uploaded photo', function (): void {
    Storage::fake('public');
    $drims = preparednessUser('DRIMS', 'drims-actions@example.test');
    $report = preparednessDraft($drims);

    $this->actingAs($drims)->post("/preparedness-for-response/reports/{$report->id}/action-pages")
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(PreparednessActionPage::query()->where('preparedness_report_id', $report->id)->count())->toBe(3);
    $page = PreparednessActionPage::query()->where('preparedness_report_id', $report->id)->latest('id')->firstOrFail();
    $this->actingAs($drims)->post("/preparedness-for-response/reports/{$report->id}/action-pages/{$page->id}", [
        '_method' => 'PATCH',
        'actions' => ['Coordinated with LGUs', 'Deployed assessment teams', 'Monitored evacuation centers'],
        'captions' => ['LGU coordination meeting', 'Assessment teams deployed', 'Evacuation center monitoring'],
        'images' => [
            UploadedFile::fake()->image('action-one.jpg', 800, 1200),
            UploadedFile::fake()->image('action-two.jpg', 800, 1200),
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $page->refresh();
    expect($page->actions[0])->toBe('Coordinated with LGUs')
        ->and($page->captions[0])->toBe('LGU coordination meeting')
        ->and($page->image_paths[0])->toStartWith('/storage/preparedness-actions/')
        ->and($page->image_paths[1])->toStartWith('/storage/preparedness-actions/');
    Storage::disk('public')->assertExists(str_replace('/storage/', '', $page->image_paths[0]));
    Storage::disk('public')->assertExists(str_replace('/storage/', '', $page->image_paths[1]));
});

it('lists drafts and finalized reports and prevents finalized edits', function (): void {
    $drims = preparednessUser('DRIMS', 'drims-lifecycle@example.test');
    $report = preparednessDraft($drims, 'Typhoon Readiness Report');

    $this->actingAs($drims)->get(route('preparedness-for-response.index'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('PreparednessForResponse/Reports')->where('reports.0.title', 'Typhoon Readiness Report'));

    $this->actingAs($drims)->patch(route('preparedness-for-response.reports.finalize', $report), [
        'data_snapshot' => ['asOf' => now()->toIso8601String(), 'summary' => ['stockpile_quantity' => 123]],
    ])->assertRedirect();
    expect($report->fresh()->status)->toBe('finalized')->and($report->fresh()->finalized_at)->not->toBeNull();

    $this->actingAs($drims)->get(route('preparedness-for-response.reports.show', $report))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('PreparednessForResponse/Index')
            ->where('summary.stockpile_quantity', 123)
            ->where('canEditPreparednessData', false));

    $this->actingAs($drims)->patch(route('preparedness-for-response.data.update', [$report, 'qrt-coverage']), ['rows' => []])->assertStatus(422);

    $this->actingAs($drims)->patch(route('preparedness-for-response.reports.revise', $report))->assertRedirect();
    expect($report->fresh()->status)->toBe('revised')->and($report->fresh()->data_snapshot)->toBeNull();
});

it('allows finalized reports to be reopened and edited after the reporting schedule cutoff', function (): void {
    $drims = preparednessUser('DRIMS', 'drims-expired-revision@example.test');
    $report = preparednessDraft($drims);
    $report->update(['status' => 'finalized', 'revision_deadline' => now()->subMinute(), 'data_snapshot' => ['asOf' => now()->toIso8601String()]]);

    $this->actingAs($drims)->get(route('preparedness-for-response.reports.show', $report))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canRevisePreparednessReport', true)
            ->where('canEditPreparednessData', false));

    $this->actingAs($drims)->patch(route('preparedness-for-response.reports.revise', $report))->assertRedirect();
    expect($report->fresh()->status)->toBe('revised')->and($report->fresh()->canBeRevised())->toBeTrue();

    $this->actingAs($drims)->patch(route('preparedness-for-response.data.update', [$report, 'qrt-coverage']), ['rows' => []])
        ->assertRedirect()->assertSessionHasNoErrors();
});

it('deletes a report and its isolated editable content', function (): void {
    $drims = preparednessUser('DRIMS', 'drims-delete-report@example.test');
    $report = preparednessDraft($drims, 'Disposable Draft');
    expect(PreparednessBriefingIntro::where('preparedness_report_id', $report->id)->exists())->toBeTrue();

    $this->actingAs($drims)->delete(route('preparedness-for-response.reports.destroy', $report))->assertRedirect(route('preparedness-for-response.index'));
    expect(PreparednessReport::find($report->id))->toBeNull()
        ->and(PreparednessBriefingIntro::where('preparedness_report_id', $report->id)->exists())->toBeFalse();
});
