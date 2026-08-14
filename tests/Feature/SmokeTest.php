<?php

use App\Models\AssistanceRequest;
use App\Models\LguDirectoryEntry;
use App\Models\OperationalLibraryValue;
use App\Models\User;
use App\Services\OfficialAdvisoryService;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('completes Caraga Connect during the OAuth callback', function (): void {
    config()->set('app.url', 'https://drmd-focrg-is.test');
    config()->set('services.cc_idp', [
        'client_id' => '158',
        'client_secret' => 'test-secret',
        'authorize_url' => 'https://caraga-connect-api-staging.dswd.gov.ph/sso/login',
        'token_url' => 'https://caraga-connect-api-staging.dswd.gov.ph/oauth/token',
        'userinfo_url' => 'https://caraga-connect-api-staging.dswd.gov.ph/api/userinfo',
        'redirect_uri' => 'http://127.0.0.1:8010/login-sso/callback',
        'scope' => 'openid profile email',
        'verify_ssl' => false,
        'default_office' => 'DRRS',
    ]);
    Http::fake([
        '*/sso/login*' => Http::response('<html>Authorization Request</html>'),
        '*/oauth/token' => Http::response(['access_token' => 'access-token', 'refresh_token' => 'refresh-token', 'expires_in' => 3600]),
        '*/api/userinfo' => Http::response([
            'sub' => 'cc-user-001',
            'name' => 'Caraga Connect User',
            'email' => 'caraga-connect@example.test',
            'preferred_username' => 'cc.user',
            'id_number' => '16-00001',
            'contact_number' => '09170000000',
            'mobile_no' => '09171111111',
            'image' => 'https://caraga-connect-api-staging.dswd.gov.ph/avatar.png',
        ]),
    ]);

    $authorization = $this->get(route('sso.login'))->assertRedirect();
    parse_str((string) parse_url($authorization->headers->get('Location'), PHP_URL_QUERY), $authorizeQuery);
    expect($authorizeQuery['redirect_uri'])->toBe('http://127.0.0.1:8010/login-sso/callback');

    $callback = $this->get(route('sso.callback', ['state' => $authorizeQuery['state'], 'code' => 'authorization-code']))
        ->assertRedirect(route('access.request'));
    $this->assertAuthenticated();
    $user = User::where('username', 'cc.user')->firstOrFail();
    expect($user->id_number)->toBe('16-00001')
        ->and($user->office)->toBe('DRRS')
        ->and($user->position)->toBeNull()
        ->and($user->designation)->toBeNull()
        ->and($user->area_of_assignment)->toBeNull()
        ->and($user->employment_status)->toBeNull()
        ->and($user->contact_number)->toBe('09170000000')
        ->and($user->mobile_no)->toBe('09171111111')
        ->and($user->avatar)->toBe('https://caraga-connect-api-staging.dswd.gov.ph/avatar.png')
        ->and(data_get($user->sso_profile_payload, 'sso.id_number'))->toBe('16-00001');
});

it('does not erase existing employee profile fields when SSO returns only basic identity', function (): void {
    $this->seed(DatabaseSeeder::class);
    config()->set('app.url', 'https://drmd-focrg-is.test');
    config()->set('services.cc_idp', [
        'client_id' => '158',
        'client_secret' => 'test-secret',
        'authorize_url' => 'https://caraga-connect-dev.dswd.gov.ph/sso/login',
        'token_url' => 'https://caraga-connect-dev.dswd.gov.ph/oauth/token',
        'userinfo_url' => 'https://caraga-connect-dev.dswd.gov.ph/api/userinfo',
        'redirect_uri' => 'https://drmd-focrg-is.test/login-sso/callback',
        'scope' => 'basic',
        'verify_ssl' => false,
        'default_office' => 'DRRS',
        'bypass_mfa' => true,
    ]);

    $user = User::where('email', 'drmd-aa@example.test')->firstOrFail();
    $user->forceFill([
        'sso_sub' => '117',
        'username' => 'rlongue',
        'email' => 'rlongue@dswd.gov.ph',
        'name' => 'Roger L. Ongue',
        'id_number' => '16-11720',
        'office' => 'Disaster Response Information Management Section',
        'position' => 'Project Development Officer II',
        'designation' => 'DRIMS System Developer',
        'area_of_assignment' => 'DRMD - DRIMS',
        'employment_status' => 'Contract of Service',
        'avatar' => 'https://caraga-connect-dev.dswd.gov.ph/storage/employees/rlongue.jpg',
        'contact_number' => '09510914209',
    ])->save();

    Http::fake([
        '*/sso/login*' => Http::response('<html>Authorization Request</html>'),
        '*/oauth/token' => Http::response(['access_token' => 'access-token', 'refresh_token' => 'refresh-token', 'expires_in' => 3600]),
        '*/api/userinfo' => Http::response([
            'sub' => '117',
            'name' => 'Roger L. Ongue',
            'email' => 'rlongue@dswd.gov.ph',
            'preferred_username' => 'rlongue',
            'id_number' => '16-11720',
            'contact_number' => '09510914209',
        ]),
        '*' => Http::response([], 404),
    ]);

    $authorization = $this->get(route('sso.login'))->assertRedirect();
    parse_str((string) parse_url($authorization->headers->get('Location'), PHP_URL_QUERY), $authorizeQuery);
    $this->get(route('sso.callback', ['state' => $authorizeQuery['state'], 'code' => 'authorization-code']))
        ->assertRedirect(route('dashboard'));

    $user->refresh();
    expect($user->office)->toBe('Disaster Response Information Management Section')
        ->and($user->position)->toBe('Project Development Officer II')
        ->and($user->designation)->toBe('DRIMS System Developer')
        ->and($user->area_of_assignment)->toBe('DRMD - DRIMS')
        ->and($user->employment_status)->toBe('Contract of Service')
        ->and($user->avatar)->toBe('https://caraga-connect-dev.dswd.gov.ph/storage/employees/rlongue.jpg')
        ->and($user->contact_number)->toBe('09510914209');
});

it('returns to login when Caraga Connect rejects the configured callback', function (): void {
    config()->set('app.url', 'https://drmd-focrg-is.test');
    config()->set('services.cc_idp', [
        'client_id' => '189',
        'client_secret' => 'test-secret',
        'authorize_url' => 'https://caraga-connect.dswd.gov.ph/sso/login',
        'token_url' => 'https://caraga-connect.dswd.gov.ph/oauth/token',
        'userinfo_url' => 'https://caraga-connect.dswd.gov.ph/api/userinfo',
        'redirect_uri' => 'https://drmd-focrg-is.test/login-sso/callback',
        'scope' => 'basic',
        'verify_ssl' => false,
        'default_office' => 'DRRS',
        'bypass_mfa' => false,
    ]);
    Http::fake([
        '*/sso/login*' => Http::response('SSO Access Denied - System Not Registered or Revoked'),
    ]);

    $this->get(route('sso.login'))
        ->assertRedirect('https://drmd-focrg-is.test/login?sso_error='.urlencode('Caraga Connect rejected this SSO client or callback URL. Please ask Caraga Connect admin/RICTMS to register this exact redirect URI: https://drmd-focrg-is.test/login-sso/callback'));
});

it('refreshes the employee profile through the documented MyPortal API token flow', function (): void {
    $this->seed(DatabaseSeeder::class);
    config()->set('services.cc_idp.myportal_login_url', 'https://caraga-connect-dev.dswd.gov.ph/api/v1/staff/login');
    config()->set('services.cc_idp.myportal_me_details_url', 'https://caraga-connect-dev.dswd.gov.ph/api/v1/staff/portal/me/details');
    config()->set('services.cc_idp.myportal_access_token', null);
    config()->set('services.cc_idp.myportal_username', 'rlongue');
    config()->set('services.cc_idp.myportal_password', 'portal-passkey');
    config()->set('services.cc_idp.verify_ssl', false);
    Cache::forget('myportal:staff-token:'.sha1('https://caraga-connect-dev.dswd.gov.ph/api/v1/staff/login|rlongue'));

    $user = User::where('email', 'drmd-aa@example.test')->firstOrFail();

    Http::fake([
        '*/api/v1/staff/login' => Http::response([
            'status' => 'success',
            'description' => 'OK',
            'token' => 'portal-token',
        ]),
        '*/api/v1/staff/portal/me/details' => function ($request) {
            $authorization = $request->header('Authorization');
            $authorization = is_array($authorization) ? ($authorization[0] ?? null) : $authorization;

            if ($authorization !== 'Bearer portal-token') {
                return Http::response(['message' => 'Unauthenticated.'], 401);
            }

            return Http::response([
                'status' => 'success',
                'data' => [
                    'employee_id' => '1410',
                    'id_number' => '16-11720',
                    'first_name' => 'ROGER',
                    'middle_name' => 'L',
                    'last_name' => 'ONGUE',
                    'username' => 'rlongue',
                    'contact' => '09510914209',
                    'position' => 'PROJECT DEVELOPMENT OFFICER II',
                    'division' => 'Disaster Response Management Division',
                    'section' => 'Disaster Response Information Management Section',
                    'area_of_assignment' => 'Field Office Caraga',
                    'image_path' => 'https://caraga-portal.dswd.gov.ph/media/picture/roger.jpg',
                    'status' => 'Active',
                ],
            ]);
        },
    ]);

    $this->actingAs($user)
        ->post(route('profile.myportal.sync'))
        ->assertRedirect()
        ->assertSessionHas('success', 'Employee profile refreshed from MyPortal.');

    $user->refresh();
    expect($user->name)->toBe('Roger L. Ongue')
        ->and($user->username)->toBe('rlongue')
        ->and($user->id_number)->toBe('16-11720')
        ->and($user->position)->toBe('PROJECT DEVELOPMENT OFFICER II')
        ->and($user->office)->toBe('Disaster Response Information Management Section')
        ->and($user->area_of_assignment)->toBe('Field Office Caraga')
        ->and($user->employment_status)->toBe('Active')
        ->and($user->avatar)->toBe('https://caraga-portal.dswd.gov.ph/media/picture/roger.jpg')
        ->and(data_get($user->sso_profile_payload, 'myportal.data.image_path'))->toBe('https://caraga-portal.dswd.gov.ph/media/picture/roger.jpg');
});

it('refreshes the employee profile with a configured MyPortal access token', function (): void {
    $this->seed(DatabaseSeeder::class);
    config()->set('services.cc_idp.myportal_me_details_url', 'https://caraga-connect-dev.dswd.gov.ph/api/v1/staff/portal/me/details');
    config()->set('services.cc_idp.myportal_access_token', 'ready-token');
    config()->set('services.cc_idp.verify_ssl', false);

    $user = User::where('email', 'drmd-aa@example.test')->firstOrFail();

    Http::fake([
        '*/api/v1/staff/login' => Http::response(['message' => 'This should not be called when a token is configured.'], 500),
        '*/api/v1/staff/portal/me/details' => function ($request) {
            $authorization = $request->header('Authorization');
            $authorization = is_array($authorization) ? ($authorization[0] ?? null) : $authorization;

            expect($authorization)->toBe('Bearer ready-token');

            return Http::response([
                'status' => 'success',
                'data' => [
                    'employee_id' => '1410',
                    'id_number' => '16-11720',
                    'first_name' => 'ROGER',
                    'middle_name' => 'L',
                    'last_name' => 'ONGUE',
                    'username' => 'rlongue',
                    'contact' => '09510914209',
                    'position' => 'ADMINISTRATIVE ASSISTANT II',
                    'section' => 'Disaster Response Information Management Section',
                    'area_of_assignment' => 'Field Office Caraga',
                    'status' => 'Active',
                    'image_path' => 'https://caraga-portal.dswd.gov.ph/media/picture/roger.jpg',
                ],
            ]);
        },
    ]);

    $this->actingAs($user)
        ->post(route('profile.myportal.sync'))
        ->assertRedirect()
        ->assertSessionHas('success', 'Employee profile refreshed from MyPortal.');

    $user->refresh();
    expect($user->name)->toBe('Roger L. Ongue')
        ->and($user->id_number)->toBe('16-11720')
        ->and($user->position)->toBe('ADMINISTRATIVE ASSISTANT II')
        ->and($user->area_of_assignment)->toBe('Field Office Caraga')
        ->and($user->employment_status)->toBe('Active')
        ->and($user->avatar)->toBe('https://caraga-portal.dswd.gov.ph/media/picture/roger.jpg');
});

it('saves the employee area of responsibility selections for DRRS and DRIMS', function (): void {
    $this->seed(DatabaseSeeder::class);

    $user = User::where('email', 'drmd-aa@example.test')->firstOrFail();
    $user->assignRole('DRRS');

    $this->actingAs($user)
        ->post(route('profile.aor.update'), [
            'aor_provinces' => ['1600000000'],
            'aor_districts' => ['1601000000'],
            'aor_cities_municipalities' => ['1601010001'],
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Area of responsibility updated.');

    $user->refresh();
    expect($user->aor_provinces)->toBe(['1600000000'])
        ->and($user->aor_districts)->toBe(['1601000000'])
        ->and($user->aor_cities_municipalities)->toBe(['1601010001'])
        ->and($user->aorEntries()->pluck('psgc_code')->all())->toBe(['1600000000', '1601000000', '1601010001']);
});

it('reports rejected MyPortal credentials without breaking the profile page', function (): void {
    $this->seed(DatabaseSeeder::class);
    config()->set('services.cc_idp.myportal_login_url', 'https://caraga-connect-dev.dswd.gov.ph/api/v1/staff/login');
    config()->set('services.cc_idp.myportal_access_token', null);
    config()->set('services.cc_idp.myportal_username', 'rlongue');
    config()->set('services.cc_idp.myportal_password', 'wrong-passkey');
    config()->set('services.cc_idp.verify_ssl', false);
    Cache::forget('myportal:staff-token:'.sha1('https://caraga-connect-dev.dswd.gov.ph/api/v1/staff/login|rlongue'));

    Http::fake([
        '*/api/v1/staff/login' => Http::response(['message' => 'Unauthorized'], 401),
    ]);

    $user = User::where('email', 'drmd-aa@example.test')->firstOrFail();

    $this->actingAs($user)
        ->post(route('profile.myportal.sync'))
        ->assertRedirect()
        ->assertSessionHas('error', 'MyPortal login failed. The configured portal username/passkey was rejected.');
});

it('uses the long system name publicly and the short system name in authenticated layouts', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();
    $systemName = OperationalLibraryValue::create([
        'library_type' => 'system_name',
        'value' => 'Legacy System Name',
        'context' => 'all',
        'metadata' => ['short_name' => 'Legacy Name'],
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->put("/operational-library/{$systemName->id}", [
            'library_type' => 'system_name',
            'value' => 'Disaster Response Management Division Integrated System',
            'short_name' => 'DRMD Integrated System',
            'context' => 'all',
            'is_active' => true,
        ])
        ->assertRedirect();

    $systemName->refresh();
    expect($systemName->value)->toBe('Disaster Response Management Division Integrated System')
        ->and(data_get($systemName->metadata, 'short_name'))->toBe('DRMD Integrated System');

    $this->get('/libraries')->assertInertia(fn ($page) => $page
        ->component('Inventory/FniLibrary')
        ->where('systemNameLong', 'Disaster Response Management Division Integrated System')
        ->where('systemNameShort', 'DRMD Integrated System'));
    $this->get('/fni-library')->assertRedirect('/libraries');

    $this->post('/logout')->assertRedirect();
    $this->get('/login')->assertInertia(fn ($page) => $page
        ->component('Auth/Login')
        ->where('systemNameLong', 'Disaster Response Management Division Integrated System')
        ->where('systemNameShort', 'DRMD Integrated System'));
    $this->get('/login')
        ->assertSee('Disaster Response Management Division Integrated System')
        ->assertDontSee('Legacy System Name');
});

it('loads the dashboard for an authenticated RROS user', function (): void {
    $this->seed(DatabaseSeeder::class);

    $user = User::where('email', 'rros@example.test')->firstOrFail();

    $this->actingAs($user)
        ->get('/')
        ->assertOk();
});

it('loads the DRRS request and assessment registries', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'drrs@example.test')->firstOrFail();

    $this->actingAs($user)
        ->get('/requests')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Requests/Index')
            ->has('requests')
            ->has('assessments')
            ->has('approved')
            ->has('workspaceSummary')
            ->has('warehouseStock'));
});

it('returns a safe configuration error when Groq is not configured', function (): void {
    $this->seed(DatabaseSeeder::class);
    config()->set('services.groq.api_key', null);
    $user = User::where('email', 'drrs@example.test')->firstOrFail();

    $this->actingAs($user)
        ->postJson('/requests/polish-assessment', ['mode' => 'polish', 'text' => 'Initial assessment draft.'])
        ->assertStatus(503)
        ->assertJsonPath('message', 'Groq AI is not configured. Add GROQ_API_KEY to the server environment, then clear the configuration cache.');
});

it('lets AI Roger answer through Groq when configured', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'rros@example.test')->firstOrFail();
    AssistanceRequest::create([
        'reference_number' => 'REQ-AIROGER-CONTEXT',
        'requesting_agency' => 'Test City LGU',
        'province' => 'Agusan del Norte',
        'municipality' => 'Test City',
        'requester' => 'Test Requester',
        'date_requested' => now()->toDateString(),
        'purpose' => 'Relief Augmentation',
        'status' => 'endorsed',
        'submitted_at' => now(),
    ]);
    config()->set('services.groq.api_key', 'test-groq-key');
    config()->set('services.groq.base_url', 'https://api.groq.test/openai/v1');
    config()->set('services.groq.model', 'test-model');

    Http::fake([
        'https://api.groq.test/openai/v1/chat/completions' => Http::response([
            'choices' => [
                ['message' => ['content' => 'DROMIS is the Disaster Response Operations Management Integrated System.']],
            ],
        ]),
    ]);

    $this->actingAs($user)
        ->postJson(route('ai-roger.chat'), [
            'message' => 'What can you tell me about REQ-AIROGER-CONTEXT?',
            'current_url' => '/dashboard',
            'history' => [
                ['role' => 'assistant', 'content' => 'Hi, I am AI Roger.'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('provider', 'Groq')
        ->assertJsonPath('model', 'test-model')
        ->assertJsonPath('answer', 'DROMIS is the Disaster Response Operations Management Integrated System.');

    Http::assertSent(function ($request): bool {
        $payload = $request->data();
        $systemPrompt = (string) data_get($payload, 'messages.0.content');

        return str_contains($systemPrompt, 'Permission-scoped database context')
            && str_contains($systemPrompt, 'Permission-scoped page and process map')
            && str_contains($systemPrompt, 'Developer: Roger L. Ongue, PDO II')
            && str_contains($systemPrompt, '/requests')
            && str_contains($systemPrompt, 'REQ-AIROGER-CONTEXT')
            && str_contains($systemPrompt, 'Test City LGU');
    });
});

it('keeps assessment generation and polishing as distinct Groq operations', function (): void {
    $this->seed(DatabaseSeeder::class);
    config()->set('services.groq.api_key', 'test-key');
    config()->set('services.groq.base_url', 'https://api.groq.test/openai/v1');
    config()->set('services.groq.model', 'test-model');
    Http::fake(fn () => Http::response([
        'choices' => [['message' => ['content' => 'Official assessment narrative.']]],
    ]));
    $user = User::where('email', 'drrs@example.test')->firstOrFail();

    $this->actingAs($user)
        ->postJson('/requests/polish-assessment', [
            'mode' => 'generate',
            'requesting_agency' => 'MLGU - Tubod, SDN',
            'affected_families' => 755,
            'form_context' => [
                'lgu_level' => 'MLGU',
                'municipality' => 'TUBOD',
                'province' => 'Surigao del Norte',
            ],
        ])
        ->assertOk()
        ->assertJsonPath('polished', 'Official assessment narrative.');

    $this->actingAs($user)
        ->postJson('/requests/polish-assessment', [
            'mode' => 'polish',
            'text' => 'Existing verified narrative.',
        ])
        ->assertOk();

    $requests = Http::recorded();
    expect(data_get($requests[0][0]->data(), 'messages.0.content'))->toContain('Create a new narrative from the encoded facts')
        ->and(data_get($requests[0][0]->data(), 'messages.0.content'))->toContain('Never mention the assigned social worker')
        ->and(data_get($requests[0][0]->data(), 'messages.0.content'))->toContain('FNI always means Food and Non-Food Items')
        ->and(data_get($requests[0][0]->data(), 'messages.1.content'))->toContain('Municipal Local Government Unit (MLGU) of Tubod, Surigao del Norte')
        ->and(data_get($requests[0][0]->data(), 'messages.1.content'))->toContain('Affected geographic area: municipality of Tubod, Surigao del Norte')
        ->and(data_get($requests[0][0]->data(), 'messages.0.content'))->toContain('Only the geographic area may experience')
        ->and(data_get($requests[0][0]->data(), 'messages.0.content'))->toContain('Only the LGU may report')
        ->and(data_get($requests[1][0]->data(), 'messages.0.content'))->toContain('Perform a conservative polish');
});

it('passes separate incident occurrences to Groq without collapsing them', function (): void {
    $this->seed(DatabaseSeeder::class);
    config()->set('services.groq.api_key', 'test-key');
    config()->set('services.groq.base_url', 'https://api.groq.test/openai/v1');
    config()->set('services.groq.model', 'test-model');
    Http::fake(fn () => Http::response([
        'choices' => [['message' => ['content' => 'Consolidated multi-incident assessment.']]],
    ]));
    $user = User::where('email', 'drrs@example.test')->firstOrFail();

    $this->actingAs($user)->postJson('/requests/polish-assessment', [
        'mode' => 'generate',
        'requesting_agency' => 'MLGU - Tubod, SDN',
        'affected_families' => 5,
        'form_context' => [
            'lgu_level' => 'MLGU',
            'municipality' => 'TUBOD',
            'province' => 'Surigao del Norte',
            'incidents' => [
                ['incident_type' => 'Fire Incident', 'occurrence_at' => '2026-08-01', 'barangay' => 'Marga', 'affected_families' => 2],
                ['incident_type' => 'Fire Incident', 'occurrence_at' => '2026-08-09', 'barangay' => 'San Pablo', 'affected_families' => 3],
            ],
        ],
    ])->assertOk()->assertJsonPath('polished', 'Consolidated multi-incident assessment.');

    Http::assertSent(function ($request): bool {
        $payload = $request->data();
        $system = (string) data_get($payload, 'messages.0.content');
        $facts = (string) data_get($payload, 'messages.1.content');

        return str_contains($system, 'multiple separate incidents')
            && str_contains($facts, 'Number of separate incidents: 2')
            && str_contains($facts, 'Marga')
            && str_contains($facts, 'San Pablo');
    });
});

it('generates human LGU situation overviews with distinct paragraphs from relevant encoded facts', function (): void {
    $this->seed(DatabaseSeeder::class);
    config()->set('services.groq.api_key', 'test-key');
    config()->set('services.groq.base_url', 'https://api.groq.test/openai/v1');
    config()->set('services.groq.model', 'test-model');
    Http::fake(fn () => Http::response([
        'choices' => [['message' => ['content' => "Current incident situation.\n\nSummarized LGU effects.\n\nChallenges, gaps, and response actions.\n\nContinuing LGU commitment."]]],
    ]));
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();
    $facts = [
        'reporting_lgu' => 'Municipality of Test',
        'report_as_of' => '2026-07-27T10:30',
        'incident' => [
            'type' => 'Effects of Thunderstorms',
            'location' => 'Municipality of Test',
            'affected_barangay_count' => 3,
        ],
        'affected_population_totals' => ['families' => 1250, 'persons' => 4875],
        'lgu_response_actions' => [['action_intervention' => 'Conducted local validation']],
        'official_agency_advisories_status' => 'supplied',
        'official_agency_advisories' => [[
            'agency' => 'PAGASA',
            'summary' => 'PAGASA identified a weather system affecting Mindanao.',
        ]],
    ];

    $this->actingAs($user)
        ->postJson('/lgu/dromic-sitrep/polish', [
            'mode' => 'generate',
            'facts' => $facts,
        ])
        ->assertOk()
        ->assertJsonPath('provider', 'Groq');

    $this->actingAs($user)
        ->postJson('/lgu/dromic-sitrep/polish', [
            'mode' => 'polish',
            'text' => 'The Municipality of Test reports an incident.',
            'facts' => $facts,
        ])
        ->assertOk();

    $requests = Http::recorded();
    $generatePayload = $requests[0][0]->data();
    $polishPayload = $requests[1][0]->data();
    $systemPrompt = (string) data_get($generatePayload, 'messages.0.content');

    expect($systemPrompt)
        ->toContain('LGU employee responsible')
        ->toContain('Return exactly four narrative paragraphs')
        ->toContain('Paragraph 1 — Current incident situation')
        ->toContain('Paragraph 2 — Summarized LGU-validated effects')
        ->toContain('Never name, list, or enumerate the affected barangays')
        ->toContain('Paragraph 3 — Challenges, gaps, and response')
        ->toContain('Paragraph 4 — Report conclusion')
        ->toContain('Never mix PAGASA/PHIVOLCS figures with LGU-validated affected-population figures')
        ->toContain('complete official term followed by the acronym in parentheses')
        ->toContain('Never write "as reported by the local government unit,"')
        ->toContain('omit it silently')
        ->and(data_get($generatePayload, 'max_completion_tokens'))->toBe(1000)
        ->and(data_get($generatePayload, 'messages.1.content'))->toContain('Municipality of Test')
        ->and(data_get($generatePayload, 'messages.1.content'))->toContain('4875')
        ->and(data_get($generatePayload, 'messages.1.content'))->toContain('exactly four distinct paragraphs')
        ->and(data_get($polishPayload, 'messages.1.content'))->toContain('Existing draft')
        ->and(data_get($polishPayload, 'messages.1.content'))->toContain('exactly four distinct paragraphs');
});

it('builds fire Situation Overview prompts with fireout, named barangays, and no PAGASA when advisories are N/A', function (): void {
    $this->seed(DatabaseSeeder::class);
    config()->set('services.groq.api_key', 'test-key');
    config()->set('services.groq.base_url', 'https://api.groq.test/openai/v1');
    config()->set('services.groq.model', 'test-model');
    Http::fake(fn () => Http::response([
        'choices' => [['message' => ['content' => "A fire affected Barangay San Juan.\n\nFive families were affected in Barangay San Juan.\n\nThe LGU conducted local validation and assisted the affected families.\n\nThe report presents the final validated situation."]]],
    ]));
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();

    $this->actingAs($user)
        ->postJson('/lgu/dromic-sitrep/polish', [
            'mode' => 'generate',
            'facts' => [
                'reporting_lgu' => 'Municipality of Test',
                'report_classification' => 'first_and_final',
                'incident' => [
                    'type' => 'Fire Incident',
                    'specific_details' => 'Residential house fire',
                    'location' => 'Test, Surigao del Norte',
                    'affected_barangays' => ['San Juan'],
                    'affected_barangay_count' => 1,
                    'occurrence_datetime' => '2 August 2026, 1:15 PM',
                    'status' => 'Ended',
                    'fireout' => '2 August 2026, 3:40 PM',
                    'ended_datetime' => '2 August 2026, 3:40 PM',
                ],
                'affected_population_totals' => ['families' => 5, 'persons' => 18],
                'official_agency_advisories_not_applicable' => true,
                'official_agency_advisories_status' => 'not_applicable',
                'official_agency_advisories' => [],
                'lgu_response_actions' => [['action_intervention' => 'Conducted local validation']],
            ],
        ])
        ->assertOk();

    $payload = Http::recorded()[0][0]->data();
    $systemPrompt = (string) data_get($payload, 'messages.0.content');
    $userPrompt = (string) data_get($payload, 'messages.1.content');

    expect($systemPrompt)
        ->toContain('This is a fire incident')
        ->toContain('fireout')
        ->toContain('actual name(s) of the affected barangay')
        ->toContain('never mention PAGASA or PHIVOLCS')
        ->toContain('never write that those agencies are not relevant')
        ->not->toContain('Never name, list, or enumerate the affected barangays')
        ->and($userPrompt)->toContain('San Juan')
        ->and($userPrompt)->toContain('fireout')
        ->and($userPrompt)->toContain('not_applicable')
        ->and($userPrompt)->toContain('Do not mention PAGASA, PHIVOLCS');
});

it('removes irrelevant weather geography and effects meta-commentary from the situation overview', function (): void {
    $this->seed(DatabaseSeeder::class);
    config()->set('services.groq.api_key', 'test-key');
    config()->set('services.groq.base_url', 'https://api.groq.test/openai/v1');
    config()->set('services.groq.model', 'test-model');
    Http::fakeSequence()
        ->push([
            'choices' => [['message' => ['content' => "Localized thunderstorms are affecting Tubod. The Southwest Monsoon is also affecting Luzon.\n\nThere are 45 affected families and 90 persons from the barangays of Capayahan, Cawilan, and Del Rosario, with the exact breakdown not specified in this summary.\n\nNo specific challenges or gaps were encoded. The LGU is monitoring conditions and preparing evacuation facilities.\n\nThe LGU remains committed to addressing immediate needs and coordinating support."]]],
        ])
        ->push([
            'choices' => [['message' => ['content' => "Localized thunderstorms may bring light to moderate rain over Tubod, according to the PAGASA advisory for Caraga.\n\nA total of 45 families or 90 persons are affected.\n\nThe LGU continues monitoring conditions and preparing evacuation facilities while addressing identified needs.\n\nThe LGU remains committed to sustained response operations, meeting immediate needs, and coordinating additional support with appropriate partners."]]],
        ]);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();

    $response = $this->actingAs($user)
        ->postJson('/lgu/dromic-sitrep/polish', [
            'mode' => 'generate',
            'facts' => [
                'reporting_lgu' => 'Municipality of Tubod',
                'incident' => ['type' => 'Localized Thunderstorm', 'location' => 'Tubod, Surigao del Norte'],
                'affected_population_totals' => ['families' => 45, 'persons' => 90],
                'official_agency_advisories' => [[
                    'agency' => 'PAGASA',
                    'summary' => 'Localized thunderstorms may affect Caraga. Southwest Monsoon affecting Luzon.',
                ]],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('polished', "Localized thunderstorms may bring light to moderate rain over Tubod, according to the PAGASA advisory for Caraga.\n\nA total of 45 families or 90 persons are affected.\n\nThe LGU continues monitoring conditions and preparing evacuation facilities while addressing identified needs.\n\nThe LGU remains committed to sustained response operations, meeting immediate needs, and coordinating additional support with appropriate partners.");

    $requests = Http::recorded();
    expect($requests)->toHaveCount(2)
        ->and(data_get($requests[1][0]->data(), 'messages.1.content'))
        ->toContain('Remove weather information about Luzon')
        ->toContain('Never name or enumerate the affected barangays')
        ->toContain('Remove every statement about missing');
});

it('matches an official PHIVOLCS earthquake candidate by incident date and LGU location', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();
    $eventDate = now('Asia/Manila')->format('d F Y');
    Http::fake([
        'https://earthquake.phivolcs.dost.gov.ph/EQLatest.html' => Http::response(<<<HTML
            <html><body><table>
                <tr>
                    <td><a href="2026_Earthquake_Information/July/test_B1.html">{$eventDate} - 10:15 AM</a></td>
                    <td>9.10</td><td>125.50</td><td>012</td><td>4.8</td>
                    <td>010 km N of Tubod (Surigao del Norte)</td>
                </tr>
            </table></body></html>
        HTML),
    ]);

    $this->actingAs($user)
        ->postJson('/lgu/dromic-sitrep/official-advisories', [
            'incident_type' => 'Earthquake',
            'incident_name' => 'Earthquake Incident',
            'incident_date' => now('Asia/Manila')->toDateString(),
            'province' => 'Surigao del Norte',
            'municipality' => 'Tubod',
        ])
        ->assertOk()
        ->assertJsonPath('recommendations.0.agency', 'DOST-PHIVOLCS')
        ->assertJsonPath('candidates.0.agency', 'DOST-PHIVOLCS')
        ->assertJsonPath('candidates.0.covered_location', '010 km N of Tubod (Surigao del Norte)')
        ->assertJsonPath('candidates.0.source_url', 'https://earthquake.phivolcs.dost.gov.ph/2026_Earthquake_Information/July/test_B1.html');
});

it('recommends the responsible official source for weather, fire, and other incidents', function (): void {
    $service = app(OfficialAdvisoryService::class);
    $oldDate = '2024-01-15';

    $weather = $service->lookup([
        'incident_type' => 'Flooding due to continuous rainfall',
        'incident_date' => $oldDate,
        'municipality' => 'Tubod',
        'province' => 'Surigao del Norte',
    ]);
    $fire = $service->lookup([
        'incident_type' => 'Fire Incident',
        'incident_date' => $oldDate,
        'municipality' => 'Tubod',
    ]);
    $other = $service->lookup([
        'incident_type' => 'Structural Collapse',
        'incident_date' => $oldDate,
        'municipality' => 'Tubod',
    ]);

    expect(collect($weather['recommendations'])->pluck('agency'))->toContain('PAGASA', 'DENR-MGB Region XIII')
        ->and(collect($fire['recommendations'])->pluck('agency'))->toContain('Bureau of Fire Protection')
        ->and(collect($other['recommendations'])->pluck('agency'))->toContain('OCD/NDRRMC and responsible lead agency');
});

it('imports a readable PAGASA advisory using only its official link', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();
    Http::fake([
        'https://www.pagasa.dost.gov.ph/weather/advisory-test' => Http::response(<<<'HTML'
            <html>
                <head>
                    <meta property="og:title" content="Weather Advisory No. 4">
                    <meta property="og:description" content="Issued at 8:00 AM, 27 July 2026. Moderate to heavy rainfall may affect portions of Caraga due to the Southwest Monsoon.">
                </head>
                <body><main>Official PAGASA weather advisory for Caraga.</main></body>
            </html>
        HTML, 200, ['Content-Type' => 'text/html']),
    ]);

    $this->actingAs($user)
        ->postJson('/lgu/dromic-sitrep/official-advisories/import', [
            'source_url' => 'https://www.pagasa.dost.gov.ph/weather/advisory-test',
            'incident_type' => 'Flooding due to continuous rainfall',
            'incident_date' => '2026-07-27',
            'province' => 'Surigao del Norte',
            'municipality' => 'Tubod',
        ])
        ->assertOk()
        ->assertJsonPath('source.agency', 'PAGASA')
        ->assertJsonPath('source.advisory_title', 'Weather Advisory No. 4')
        ->assertJsonPath('source.covered_location', 'Tubod, Surigao del Norte')
        ->assertJsonPath('source.content_status', 'extracted_for_review')
        ->assertJsonPath('source.source_url', 'https://www.pagasa.dost.gov.ph/weather/advisory-test')
        ->assertJsonPath('source.summary', 'Issued at 8:00 AM, 27 July 2026. Moderate to heavy rainfall may affect portions of Caraga due to the Southwest Monsoon.');
});

it('keeps an unreadable Facebook post as a reference without inventing its contents', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();
    Http::fake([
        'https://www.facebook.com/share/p/test-post/' => Http::response(
            '<html><head><title>Facebook</title></head><body>Log in to continue.</body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    $this->actingAs($user)
        ->postJson('/lgu/dromic-sitrep/official-advisories/import', [
            'source_url' => 'https://www.facebook.com/share/p/test-post/',
            'incident_type' => 'Earthquake',
            'incident_date' => '2026-07-27',
            'province' => 'Surigao del Norte',
            'municipality' => 'Tubod',
        ])
        ->assertOk()
        ->assertJsonPath('source.agency', 'Facebook source — verify DOST-PHIVOLCS page owner')
        ->assertJsonPath('source.content_status', 'reference_only')
        ->assertJsonPath('source.summary', '')
        ->assertJsonPath('source.source_url', 'https://www.facebook.com/share/p/test-post/')
        ->assertJsonFragment(['notice' => 'The Facebook link was saved, but Meta did not expose readable post text. Verify the official page and paste the relevant post text if you want Groq to use its contents.']);
});

it('extracts visible advisory facts from a pasted screenshot through Groq Vision', function (): void {
    $this->seed(DatabaseSeeder::class);
    config()->set('services.groq.api_key', 'test-key');
    config()->set('services.groq.base_url', 'https://api.groq.test/openai/v1');
    config()->set('services.groq.vision_model', 'qwen/qwen3.6-27b');
    Http::fake([
        'https://api.groq.test/openai/v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'agency' => 'DOST-PHIVOLCS',
                        'advisory_title' => 'Earthquake Information No. 1',
                        'issued_at' => '27 July 2026, 10:15 AM',
                        'covered_location' => 'Tubod, Surigao del Norte',
                        'summary' => 'A magnitude 4.8 earthquake with a depth of 12 km was shown in the advisory.',
                    ]),
                ],
            ]],
        ]),
    ]);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();
    $image = 'data:image/jpeg;base64,'.base64_encode('test-image');

    $this->actingAs($user)
        ->postJson('/lgu/dromic-sitrep/official-advisories/extract-screenshot', [
            'screenshot_data_url' => $image,
            'screenshot_name' => 'phivolcs-screenshot.jpg',
            'incident_type' => 'Earthquake',
            'incident_date' => '2026-07-27',
            'province' => 'Surigao del Norte',
            'municipality' => 'Tubod',
        ])
        ->assertOk()
        ->assertJsonPath('source.agency', 'DOST-PHIVOLCS')
        ->assertJsonPath('source.advisory_title', 'Earthquake Information No. 1')
        ->assertJsonPath('source.source_kind', 'screenshot')
        ->assertJsonPath('source.source_url', '')
        ->assertJsonPath('source.content_status', 'extracted_for_review')
        ->assertJsonPath('provider', 'Groq')
        ->assertJsonPath('model', 'qwen/qwen3.6-27b');

    $this->actingAs($user)
        ->postJson('/lgu/dromic-sitrep/official-advisories/extract-screenshot', [
            'screenshot_data_url' => $image,
            'screenshot_name' => 'same-phivolcs-screenshot.jpg',
            'incident_type' => 'Earthquake',
            'incident_date' => '2026-07-27',
            'province' => 'Surigao del Norte',
            'municipality' => 'Tubod',
        ])
        ->assertOk()
        ->assertJsonPath('cached', true)
        ->assertJsonPath('source.summary', 'A magnitude 4.8 earthquake with a depth of 12 km was shown in the advisory.');

    Http::assertSent(function ($request) use ($image): bool {
        return data_get($request->data(), 'model') === 'qwen/qwen3.6-27b'
            && data_get($request->data(), 'messages.0.content.1.type') === 'image_url'
            && data_get($request->data(), 'messages.0.content.1.image_url.url') === $image
            && str_contains((string) data_get($request->data(), 'messages.0.content.0.text'), 'Extract only text and facts that are visibly supported');
    });
    Http::assertSentCount(1);
});

it('retries advisory screenshot extraction without provider JSON mode when the vision model rejects it', function (): void {
    $this->seed(DatabaseSeeder::class);
    config()->set('services.groq.api_key', 'test-key');
    config()->set('services.groq.base_url', 'https://api.groq.test/openai/v1');
    config()->set('services.groq.vision_model', 'qwen/qwen3.6-27b');
    Http::fakeSequence()
        ->push([
            'error' => [
                'code' => 'json_validate_failed',
                'message' => 'Failed to validate JSON.',
            ],
        ], 400)
        ->push([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'agency' => 'PAGASA',
                        'advisory_title' => 'Weather Advisory',
                        'issued_at' => '27 July 2026',
                        'covered_location' => 'Caraga Region',
                        'summary' => 'Localized thunderstorms may affect portions of Caraga.',
                    ]),
                ],
            ]],
        ], 200);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();

    $this->actingAs($user)
        ->postJson('/lgu/dromic-sitrep/official-advisories/extract-screenshot', [
            'screenshot_data_url' => 'data:image/jpeg;base64,'.base64_encode('json-mode-fallback-image'),
            'incident_type' => 'Weather Disturbance',
            'incident_date' => '2026-07-27',
            'province' => 'Surigao del Norte',
            'municipality' => 'Tubod',
        ])
        ->assertOk()
        ->assertJsonPath('source.agency', 'PAGASA')
        ->assertJsonPath('source.content_status', 'extracted_for_review');

    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => data_get($request->data(), 'response_format.type') === 'json_object');
    Http::assertSent(fn ($request): bool => ! array_key_exists('response_format', $request->data()));
});

it('blocks situation overview AI while an advisory screenshot still requires attention', function (): void {
    $this->seed(DatabaseSeeder::class);
    config()->set('services.groq.api_key', 'test-key');
    Http::fake();
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();

    $this->actingAs($user)
        ->postJson('/lgu/dromic-sitrep/polish', [
            'mode' => 'generate',
            'facts' => [
                'reporting_lgu' => 'Municipality of Tubod',
                'official_agency_advisories' => [[
                    'agency' => 'PAGASA',
                    'source_kind' => 'screenshot',
                    'content_status' => 'extraction_failed',
                    'summary' => '',
                ]],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Remove the screenshot that still requires attention or paste a clearer copy before using the Situation Overview AI.');

    Http::assertNothingSent();
});

it('stores a pasted warning-agency screenshot without requiring a source URL', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();
    $image = 'data:image/jpeg;base64,'.base64_encode('saved-test-image');

    $this->actingAs($user)
        ->post('/lgu/dromic-sitrep', [
            'submission_status' => 'draft',
            'incident_type' => 'Weather Disturbance',
            'affected_barangays' => ['Tubod'],
            'incident_status' => 'Ongoing',
            'official_advisory_rows' => [[
                'agency' => 'PAGASA',
                'advisory_title' => 'Pasted PAGASA Weather Advisory',
                'summary' => 'Visible advisory text pending final LGU verification.',
                'source_url' => '',
                'source_kind' => 'screenshot',
                'content_status' => 'extracted_for_review',
                'screenshot_name' => 'pagasa-screenshot.jpg',
                'screenshot_data_url' => $image,
            ], [
                'agency' => 'DOST-PHIVOLCS',
                'advisory_title' => 'Copied PHIVOLCS earthquake information',
                'summary' => 'Magnitude and location copied from the official post.',
                'source_kind' => 'clipboard_text',
                'content_status' => 'extracted_for_review',
                'pasted_text' => 'Magnitude and location copied from the official post.',
            ]],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $record = AssistanceRequest::query()->where('submission_type', 'lgu_dromic_relief_request')->latest('id')->firstOrFail();

    expect(data_get($record->lgu_dromic_payload, 'official_advisory_rows.0.screenshot_data_url'))->toBe($image)
        ->and(data_get($record->lgu_dromic_payload, 'official_advisory_rows.0.source_url'))->toBeNull()
        ->and(data_get($record->lgu_dromic_payload, 'official_advisory_rows.1.source_kind'))->toBe('clipboard_text');
});

it('rejects unrelated source domains in the official advisory link importer', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();

    $this->actingAs($user)
        ->postJson('/lgu/dromic-sitrep/official-advisories/import', [
            'source_url' => 'https://example.com/unverified-weather-post',
            'incident_type' => 'Weather disturbance',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'For weather and earthquake sources, use an official PAGASA/PHIVOLCS website link or a Facebook post link.');

    Http::assertNothingSent();
});

it('polishes photo documentation captions through Groq', function (): void {
    $this->seed(DatabaseSeeder::class);
    config()->set('services.groq.api_key', 'test-key');
    config()->set('services.groq.base_url', 'https://api.groq.test/openai/v1');
    config()->set('services.groq.model', 'test-model');
    Http::fake(fn () => Http::response([
        'choices' => [['message' => ['content' => 'The LGU distributed family food packs to the validated affected families.']]],
    ]));
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();

    $this->actingAs($user)
        ->postJson('/lgu/dromic-sitrep/polish', [
            'mode' => 'caption',
            'text' => 'LGU gives food packs to affected families.',
            'facts' => [
                'reporting_lgu' => 'Municipality of Test',
                'encoded_lgu_response_actions' => [
                    ['action_intervention' => 'Distributed family food packs'],
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('polished', 'The LGU distributed family food packs to the validated affected families.')
        ->assertJsonPath('provider', 'Groq')
        ->assertJsonPath('model', 'test-model');

    Http::assertSent(function ($request): bool {
        $payload = $request->data();

        return data_get($payload, 'model') === 'test-model'
            && str_contains((string) data_get($payload, 'messages.0.content'), 'polish photo documentation captions')
            && str_contains((string) data_get($payload, 'messages.0.content'), 'no more than 35 words and 220 characters')
            && str_contains((string) data_get($payload, 'messages.1.content'), 'LGU gives food packs to affected families.')
            && str_contains((string) data_get($payload, 'messages.1.content'), 'Municipality of Test');
    });
});

it('requires an LGU response action before a DROMIC report can be saved as final', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'superadmin@example.test')->firstOrFail();

    $this->actingAs($user)
        ->postJson('/lgu/dromic-sitrep', [
            'submission_status' => 'final',
            'requesting_lgu' => 'Municipality of Test',
            'requester_name' => 'Test DROMIC Reporter',
            'incident_name' => 'Test Fire Incident',
            'incident_date' => '2026-07-27',
            'province' => 'Agusan del Norte',
            'municipality' => 'Municipality of Test',
            'has_relief_request' => false,
            'narrative' => 'The reporting LGU continues to validate and monitor the incident.',
            'incident_type' => 'Fire Incident',
            'affected_barangays' => ['Test Barangay'],
            'incident_status' => 'Ongoing',
            'area_rows' => [[
                'area' => 'Test Barangay',
                'affected_families' => 5,
                'affected_persons' => 20,
            ]],
            'response_action_rows' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['response_action_rows']);
});

it('downloads the response letter as a Word document generated from the official template', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'drrs@example.test')->firstOrFail();
    $user->update([
        'name' => 'Alex Rivera Worker',
        'sso_sub' => 'myportal-response-worker',
        'position' => 'Social Welfare Officer II',
        'mobile_no' => '0917 123 4567',
        'sso_profile_payload' => ['myportal' => ['data' => ['id_number' => '16-00001']]],
    ]);
    $directory = LguDirectoryEntry::create([
        'source_sheet' => 'ADN',
        'lgu_name' => 'Test City',
        'psgc_code' => '160000001',
        'lgu_level' => 'CLGU',
        'office_address' => 'Test City Hall, Test City, Agusan del Norte',
        'is_active' => true,
    ]);
    $directory->officials()->create([
        'role' => 'lce',
        'name' => 'Hon. Maria Test Santos',
        'position_designation' => 'City Mayor',
    ]);
    $directory->officials()->create([
        'role' => 'lswd_officer',
        'name' => 'Juan Test Social Worker',
        'position_designation' => 'CSWDO',
    ]);
    $request = AssistanceRequest::create([
        'reference_number' => 'REQ-WORD-TEST',
        'requesting_agency' => 'Test City Government',
        'province' => 'AGUSAN DEL NORTE',
        'municipality' => 'TEST CITY',
        'lgu_psgc_code' => '160000001',
        'requester' => 'Test Requester',
        'requester_position' => 'City Mayor',
        'date_requested' => now()->toDateString(),
        'affected_families' => 10,
        'status' => 'under_review',
        'assessment_form_data' => ['provide_augmentation' => true],
    ]);
    $request->items()->create(['item_name' => 'Family Food Pack', 'requested_quantity' => 10, 'unit' => 'box', 'priority' => 'normal']);

    $this->actingAs($user)->get("/requests/{$request->id}/response-letter")->assertStatus(422);
    $this->actingAs($user)->patchJson("/requests/{$request->id}/response-drn", [
        'prefix' => 'CARAGA-FO-DRMD-DRRMS-SS-REP',
        'year' => '26',
        'month' => '07',
        'specified' => '00622-S',
    ])->assertOk()->assertJsonPath('drn', 'CARAGA-FO-DRMD-DRRMS-SS-REP-26-07-00622-S');
    $request->refresh();

    $response = $this->actingAs($user)->get("/requests/{$request->id}/response-letter");

    $response->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    expect($response->headers->get('content-disposition'))->toContain('.docx');

    $zip = new ZipArchive;
    expect($zip->open($response->baseResponse->getFile()->getPathname()))->toBeTrue();
    $documentXml = $zip->getFromName('word/document.xml');
    $pageOneHeader = $zip->getFromName('word/header3.xml');
    $followingPageHeader = $zip->getFromName('word/header2.xml');
    $pageOneFooter = $zip->getFromName('word/footer3.xml');
    $followingPageFooter = $zip->getFromName('word/footer2.xml');
    $embeddedDswdLogo = $zip->getFromName('word/media/image2.png');
    $embeddedBagongPilipinasLogo = $zip->getFromName('word/media/image3.png');
    $zip->close();
    $document = new DOMDocument;
    $document->loadXML($documentXml);
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
    $pageOneHeaderDocument = new DOMDocument;
    $pageOneHeaderDocument->loadXML($pageOneHeader);
    $followingPageHeaderDocument = new DOMDocument;
    $followingPageHeaderDocument->loadXML($followingPageHeader);
    $dateBreakCount = $xpath->query('//w:p[contains(., "'.strtoupper(now()->format('F j, Y')).'")]//w:br')->length;
    expect($documentXml)->toContain('HON. MARIA TEST SANTOS')
        ->and($documentXml)->toContain('JUAN TEST SOCIAL WORKER')
        ->and($documentXml)->toContain('ATTENTION:')
        ->and($documentXml)->toContain('CSWDO')
        ->and($documentXml)->toContain('10 boxes of Family Food Pack')
        ->and($documentXml)->toContain('requesting family food packs intended for')
        ->and($documentXml)->toContain('After a thorough assessment conducted by our Social Worker Mr. Alex Rivera Worker')
        ->and($documentXml)->toContain('Mr. Worker will be coordinating with you through this mobile number 0917 123 4567')
        ->and($documentXml)->toContain('Regional Resource Operations Section (RROS) personnel will prepare the Requisition and Issuance Slip (RIS) of the said items')
        ->and($documentXml)->toContain('This is in reference to your letter requesting')
        ->and($documentXml)->toContain('disaster-affected')
        ->and($documentXml)->toContain('above-mentioned number of affected families')
        ->and($documentXml)->toContain('Local Government Unit Warehouse')
        ->and(strpos($documentXml, 'This is in reference'))->toBeLessThan(strpos($documentXml, 'After a thorough assessment'))
        ->and(strpos($documentXml, 'After a thorough assessment'))->toBeLessThan(strpos($documentXml, 'Regional Resource Operations Section'))
        ->and($documentXml)->toContain('Dear ')
        ->and($documentXml)->toContain('Mayor Santos')
        ->and($documentXml)->toContain('CARAGA-FO-DRMD-DRRMS-SS-REP-26-07-00622-S')
        ->and($documentXml)->toContain('Test City, Agusan del Norte')
        ->and($documentXml)->not->toContain('Test City Hall')
        ->and($documentXml)->not->toContain('TEST CITY')
        ->and($documentXml)->toContain('JSP / AAA / JLM / 1628')
        ->and(substr_count($documentXml, 'pageBreakBefore'))->toBeGreaterThanOrEqual(1)
        ->and($documentXml)->not->toContain('PABLO YVES')
        ->and($documentXml)->not->toContain('Jhon Carlo B. Roxas')
        ->and($documentXml)->not->toContain('requesting for')
        ->and($documentXml)->not->toContain('which was occurred')
        ->and($documentXml)->not->toContain('in the above-number')
        ->and($pageOneHeader)->toContain('w:drawing')
        ->and(sha1($embeddedDswdLogo))->toBe(sha1_file(public_path('images/dswd_logo_3.png')))
        ->and(sha1($embeddedBagongPilipinasLogo))->toBe(sha1_file(public_path('images/Bagong_PilipinasTransparent.png')))
        ->and($pageOneHeader)->toContain('<wp:posOffset>685800</wp:posOffset>')
        ->and($pageOneHeader)->toContain('<wp:posOffset>1930400</wp:posOffset>')
        ->and($pageOneHeader)->toContain('cx="1168400" cy="459385"')
        ->and($pageOneHeader)->toContain('cx="381000" cy="381000"')
        ->and(substr_count($pageOneHeader, '<w:drawing>'))->toBe(2)
        ->and($pageOneHeader)->not->toContain('AlternateContent')
        ->and($pageOneHeader)->not->toContain('<w:tbl>')
        ->and($pageOneHeader)->not->toContain('DRN:')
        ->and(substr_count($pageOneHeader, 'layoutInCell="0"'))->toBe(2)
        ->and($pageOneHeader)->not->toContain('l="68745" t="5246" r="7196" b="16566"')
        ->and($followingPageHeaderDocument->textContent)->toBe($pageOneHeaderDocument->textContent)
        ->and(substr_count($followingPageHeader, '<w:drawing>'))->toBe(substr_count($pageOneHeader, '<w:drawing>'))
        ->and($followingPageFooter)->toBe($pageOneFooter)
        ->and($pageOneFooter)->toContain('PAGE 1of 1')
        ->and($pageOneFooter)->toContain('DSWD Field Office Caraga')
        ->and($dateBreakCount)->toBe(0)
        ->and($xpath->query('//w:p[contains(., "HON. MARIA TEST SANTOS") and not(contains(., "ATTENTION:"))]//w:br')->length)->toBeGreaterThanOrEqual(2)
        ->and($xpath->query('//w:p[contains(., "ATTENTION:")]//w:br')->length)->toBeGreaterThanOrEqual(2)
        ->and($xpath->query('//w:p[contains(., "ATTENTION:")]/w:pPr/w:tabs/w:tab[@w:pos="1380"]')->length)->toBeGreaterThanOrEqual(1)
        ->and($xpath->query('//w:p[contains(., "ATTENTION:")]//w:tab')->length)->toBeGreaterThanOrEqual(1)
        ->and($xpath->query('//w:p[contains(., "CSWDO")]/w:pPr/w:ind[@w:left="1380"]')->length)->toBeGreaterThanOrEqual(1)
        ->and($xpath->query('//w:p[contains(., "CSWDO")]//w:tab')->length)->toBe(0)
        ->and($xpath->query('//w:p[contains(., "Dear ") and contains(., "Greetings of service excellence")]//w:br')->length)->toBeGreaterThanOrEqual(4)
        ->and($xpath->query('//w:p[contains(., "Respectfully yours,")]//w:br')->length)->toBeGreaterThanOrEqual(1)
        ->and($xpath->query('//w:p[contains(., "MARI- FLOR A. DOLLAGA- LIBANG")]//w:br')->length)->toBeGreaterThanOrEqual(3)
        ->and($xpath->query('//w:p[contains(., "JSP / AAA / JLM / 1628")]//w:br')->length)->toBeGreaterThanOrEqual(3)
        ->and($xpath->query('//w:p[contains(., "DRN: CARAGA-FO-DRMD-DRRMS-SS-REP-26-07-00622-S")]/w:pPr/w:jc[@w:val="right"]')->length)->toBe(1)
        ->and($xpath->query('//w:p[contains(., "DRN: CARAGA-FO-DRMD-DRRMS-SS-REP-26-07-00622-S")]/w:pPr/w:ind')->length)->toBe(0)
        ->and($xpath->query('//w:p[contains(., "DRN: CARAGA-FO-DRMD-DRRMS-SS-REP-26-07-00622-S")]//w:br')->length)->toBe(0)
        ->and($xpath->query('//w:sectPr/w:pgMar[@w:top="1296"]')->length)->toBeGreaterThanOrEqual(1)
        ->and($xpath->query('//w:r[w:t[contains(., "Mayor Santos")]]/w:rPr/w:b')->length)->toBeGreaterThan(0)
        ->and($xpath->query('//w:r[w:t[contains(., "Mayor Santos")]]/w:rPr/w:i')->length)->toBeGreaterThan(0)
        ->and($xpath->query('//w:p[contains(., "This is in reference")]/w:pPr/w:spacing[@w:line="276" and not(@w:after="0")]')->length)->toBeGreaterThan(0)
        ->and($xpath->query('//w:p[contains(., "JSP / AAA / JLM / 1628")]/w:r[w:t][1]/w:rPr/w:i')->length)->toBeGreaterThan(0)
        ->and($xpath->query('//w:p[contains(., "JSP / AAA / JLM / 1628")]/w:r[w:t][1]/w:rPr/w:rFonts[@w:ascii="Arial"]')->length)->toBeGreaterThan(0)
        ->and($xpath->query('//w:p[contains(., "JSP / AAA / JLM / 1628")]/w:r[w:t][1]/w:rPr/w:sz[@w:val="16"]')->length)->toBeGreaterThan(0);

    $pdfResponse = $this->actingAs($user)
        ->get("/requests/{$request->id}/response-letter-pdf?inline=1")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    $pdfContents = file_get_contents($pdfResponse->baseResponse->getFile()->getPathname());
    expect(substr($pdfContents, 0, 4))->toBe('%PDF')
        ->and(preg_match_all('/\/Type\s*\/Page\b/', $pdfContents))->toBe(2);
});

it('renders empty assessment rows with fixed-height placeholders so the form keeps one-page layout', function (): void {
    $request = AssistanceRequest::create([
        'reference_number' => 'REQ-PDF-EMPTY-ROWS',
        'requesting_agency' => 'City Local Government Unit',
        'requester' => 'City Social Welfare and Development Officer',
        'date_requested' => now()->toDateString(),
        'date_received_by_drmd' => now()->toDateString(),
        'affected_families' => 1000,
        'recommendations' => 'Test narrative',
        'assessment_status' => 'draft',
        'status' => 'under_review',
        'assessment_form_data' => [
            'request_type' => 'Disaster',
            'response_purpose' => 'Relief Augmentation',
            'has_previous_augmentation' => false,
            'previous_augmentations' => [],
            'delivery_batches' => [],
            'provide_augmentation' => false,
            'prepared_by' => 'Test Social Worker',
            'prepared_by_position' => 'Social Welfare Officer II',
            'reviewed_by' => 'Reviewing Officer|OIC - DRMD Chief',
            'approved_by' => 'Approving Officer|Assistant Regional Director for Operations',
        ],
    ]);
    $request->items()->create([
        'item_name' => 'Family Food Pack',
        'requested_quantity' => 1000,
        'available_quantity' => 184557,
        'unit' => 'box',
        'priority' => 'normal',
    ]);

    $html = view('documents.assessment', [
        'request' => $request->load(['items.sourceWarehouse', 'assessmentType', 'incident', 'encoder']),
        'pageMargin' => 18,
    ])->render();

    expect($html)
        ->toContain('&nbsp;')
        ->and(preg_match_all('/<tr class="blank-data">/', $html))->toBe(1)
        ->and(preg_match_all('/<tr class="batch-row">/', $html))->toBe(2);
});

it('renders affected persons from DROMIC payload when assessment meta omitted them', function (): void {
    $source = AssistanceRequest::create([
        'reference_number' => 'LGU-DROMIC-PERSONS-SRC',
        'submission_type' => 'lgu_dromic_report',
        'requesting_agency' => 'MLGU Tubod',
        'requester' => 'MSWDO',
        'date_requested' => now()->toDateString(),
        'affected_families' => 2,
        'lgu_dromic_payload' => [
            'affected_families' => 2,
            'affected_persons' => 10,
            'area_rows' => [
                ['area' => 'Marga', 'affected_families' => 2, 'affected_persons' => 10],
            ],
        ],
        'status' => 'submitted',
    ]);

    $request = AssistanceRequest::create([
        'reference_number' => 'REQ-PDF-PERSONS-FALLBACK',
        'source_lgu_dromic_request_id' => $source->id,
        'requesting_agency' => 'MLGU Tubod',
        'requester' => 'MSWDO',
        'date_requested' => now()->toDateString(),
        'date_received_by_drmd' => now()->toDateString(),
        'affected_families' => 2,
        'recommendations' => 'The fire incident affected 2 families, comprising 10 persons, in Barangay Marga.',
        'assessment_status' => 'draft',
        'status' => 'under_review',
        'assessment_form_data' => [
            'request_type' => 'Disaster',
            'response_purpose' => 'Relief Augmentation',
            'has_previous_augmentation' => false,
            'provide_augmentation' => true,
            'prepared_by' => 'Test Social Worker',
            'prepared_by_position' => 'Social Welfare Officer II',
            'reviewed_by' => 'Reviewing Officer|OIC - DRMD Chief',
            'approved_by' => 'Approving Officer|Assistant Regional Director for Operations',
        ],
    ]);

    $html = view('documents.assessment', [
        'request' => $request->load(['items', 'incident', 'sourceLguDromicReport']),
        'pageMargin' => 18,
    ])->render();

    expect($request->resolvedAffectedPersons())->toBe(10)
        ->and($html)->toContain('Actual Affected Families: 2 families (10 persons)');
});

it('fits a multi-item assessment on one A4 page and keeps recommendation rows compact', function (): void {
    $request = AssistanceRequest::create([
        'reference_number' => 'REQ-PDF-MULTI-FNI',
        'requesting_agency' => 'MLGU Tubod',
        'requester' => 'MSWDO',
        'date_requested' => now()->toDateString(),
        'date_received_by_drmd' => now()->toDateString(),
        'affected_families' => 2,
        'recommendations' => 'A fire incident affected 2 families, comprising 10 persons, in Barangay Marga. The Municipal Local Government Unit of Tubod responded promptly. Augmentation of the requested FNI is recommended based on validated effects and current stock availability. DRMD continues monitoring and coordinating with the LGU.',
        'assessment_status' => 'draft',
        'status' => 'under_review',
        'assessment_form_data' => [
            'request_type' => 'Disaster',
            'response_purpose' => 'Relief Augmentation',
            'affected_persons' => 10,
            'has_previous_augmentation' => false,
            'previous_augmentations' => [
                ['unit' => null, 'description' => null, 'quantity' => null, 'remarks' => null],
                ['unit' => null, 'description' => null, 'quantity' => null, 'remarks' => null],
                ['unit' => null, 'description' => null, 'quantity' => null, 'remarks' => null],
            ],
            'delivery_batches' => [],
            'provide_augmentation' => true,
            'prepared_by' => 'Test Social Worker',
            'prepared_by_position' => 'Social Welfare Officer II',
            'reviewed_by' => 'Reviewing Officer|OIC - DRMD Chief',
            'approved_by' => 'Approving Officer|Assistant Regional Director for Operations',
        ],
    ]);

    foreach ([
        ['Family Food Pack', 10, 'box'],
        ['Sleeping Kit', 10, 'set'],
        ['Kitchen Kit', 5, 'set'],
        ['Hygiene Kit', 10, 'set'],
        ['Malong', 20, 'pc'],
        ['Family Tent', 2, 'unit'],
    ] as [$name, $qty, $unit]) {
        $request->items()->create([
            'item_name' => $name,
            'requested_quantity' => $qty,
            'available_quantity' => 100,
            'unit' => $unit,
            'priority' => 'normal',
        ]);
    }

    $viewData = [
        'request' => $request->load(['items.sourceWarehouse', 'assessmentType', 'incident', 'encoder']),
        'pageMargin' => 18,
    ];
    $html = view('documents.assessment', $viewData)->render();

    expect($html)
        ->toContain('Actual Affected Families: 2 families (10 persons)')
        ->and(preg_match_all('/<tr class="blank-data">/', $html))->toBe(1)
        ->and(preg_match_all('/<tr class="batch-row">/', $html))->toBe(2)
        ->and($html)->toMatch('/\.recommendation-items td \{ height: 9\.5pt;/');

    $pdf = Pdf::loadView('documents.assessment', $viewData)->setPaper('a4', 'portrait');
    $pdf->render();

    expect($pdf->getDomPDF()->getCanvas()->get_page_count())->toBe(1);
});

it('renders the default fit assessment on one A4 page with a substantial narrative', function (): void {
    $request = AssistanceRequest::create([
        'reference_number' => 'REQ-PDF-FIT-TEST',
        'requesting_agency' => 'City Local Government Unit',
        'requester' => 'City Social Welfare and Development Officer',
        'date_requested' => now()->toDateString(),
        'date_received_by_drmd' => now()->toDateString(),
        'affected_families' => 1000,
        'recommendations' => str_repeat('The validated incident information supports timely augmentation assistance for affected families while response coordination and stockpile monitoring continue. ', 16),
        'assessment_status' => 'draft',
        'status' => 'under_review',
        'assessment_form_data' => [
            'request_type' => 'Disaster',
            'response_purpose' => 'Relief Augmentation',
            'provide_augmentation' => true,
            'prepared_by' => 'Test Social Worker',
            'prepared_by_position' => 'Social Welfare Officer II',
            'reviewed_by' => 'Reviewing Officer|OIC - DRMD Chief',
            'approved_by' => 'Approving Officer|Assistant Regional Director for Operations',
        ],
    ]);
    $request->items()->create([
        'item_name' => 'Family Food Pack',
        'requested_quantity' => 1000,
        'available_quantity' => 184557,
        'unit' => 'box',
        'priority' => 'normal',
    ]);

    $viewData = [
        'request' => $request->load(['items.sourceWarehouse', 'assessmentType', 'incident', 'encoder']),
        'pageMargin' => 18,
    ];
    $html = view('documents.assessment', $viewData)->render();
    expect($html)->toContain(
        '<colgroup><col style="width:38%"><col style="width:62%"></colgroup>',
        '<colgroup><col style="width:29%"><col style="width:53%"><col style="width:18%"></colgroup>',
        'REMARKS / ASSESSMENT'
    );

    $pdf = Pdf::loadView('documents.assessment', $viewData)->setPaper('a4', 'portrait');
    $pdf->render();

    expect($pdf->getDomPDF()->getCanvas()->get_page_count())->toBe(1);
});
