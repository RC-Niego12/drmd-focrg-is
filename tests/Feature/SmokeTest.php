<?php

use App\Models\AssistanceRequest;
use App\Models\OperationalLibraryValue;
use App\Models\User;
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
            'requesting_agency' => 'City Government',
            'affected_families' => 755,
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
        ->and(data_get($requests[1][0]->data(), 'messages.0.content'))->toContain('Polish the current draft only');
});

it('downloads the response letter as a Word document generated from the official template', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'drrs@example.test')->firstOrFail();
    $request = AssistanceRequest::create([
        'reference_number' => 'REQ-WORD-TEST',
        'requesting_agency' => 'Test City Government',
        'province' => 'AGUSAN DEL NORTE',
        'municipality' => 'TEST CITY',
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
    expect($documentXml)->toContain('TEST REQUESTER')
        ->and($documentXml)->toContain('10 boxes of Family Food Pack')
        ->and($documentXml)->toContain('Dear ')
        ->and($documentXml)->toContain('Mayor Requester')
        ->and($documentXml)->toContain('CARAGA-FO-DRMD-DRRMS-SS-REP-26-07-00622-S')
        ->and($documentXml)->toContain('Test City, Agusan del Norte')
        ->and($documentXml)->not->toContain('TEST CITY')
        ->and($documentXml)->toContain('JSP/AAA/JLM/1628')
        ->and(substr_count($documentXml, 'pageBreakBefore'))->toBeGreaterThanOrEqual(1)
        ->and($documentXml)->not->toContain('PABLO YVES')
        ->and($documentXml)->not->toContain('Jhon Carlo B. Roxas')
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
        ->and($dateBreakCount)->toBe(2)
        ->and($xpath->query('//w:p[contains(., "TEST REQUESTER") and not(contains(., "ATTENTION:"))]//w:br')->length)->toBeGreaterThanOrEqual(2)
        ->and($xpath->query('//w:p[contains(., "ATTENTION:")]//w:br')->length)->toBeGreaterThanOrEqual(2)
        ->and($xpath->query('//w:p[contains(., "ATTENTION:")]/w:pPr/w:tabs/w:tab[@w:pos="1380"]')->length)->toBeGreaterThanOrEqual(1)
        ->and($xpath->query('//w:p[contains(., "ATTENTION:")]//w:tab')->length)->toBeGreaterThanOrEqual(1)
        ->and($xpath->query('//w:p[contains(., "LSWDO")]/w:pPr/w:tabs/w:tab[@w:pos="1380"]')->length)->toBeGreaterThanOrEqual(1)
        ->and($xpath->query('//w:p[contains(., "LSWDO")]//w:tab')->length)->toBeGreaterThanOrEqual(1)
        ->and($xpath->query('//w:p[contains(., "LSWDO")]/w:pPr/w:ind')->length)->toBe(0)
        ->and($xpath->query('//w:p[contains(., "Dear ") and contains(., "Greetings of service excellence")]//w:br')->length)->toBeGreaterThanOrEqual(4)
        ->and($xpath->query('//w:p[contains(., "Respectfully yours,")]//w:br')->length)->toBeGreaterThanOrEqual(2)
        ->and($xpath->query('//w:p[contains(., "MARI- FLOR A. DOLLAGA- LIBANG")]//w:br')->length)->toBeGreaterThanOrEqual(2)
        ->and($xpath->query('//w:p[contains(., "JSP/AAA/JLM/1628")]//w:br')->length)->toBeGreaterThanOrEqual(2)
        ->and($xpath->query('//w:p[contains(., "DRN: CARAGA-FO-DRMD-DRRMS-SS-REP-26-07-00622-S")]/w:pPr/w:jc[@w:val="right"]')->length)->toBe(1)
        ->and($xpath->query('//w:p[contains(., "DRN: CARAGA-FO-DRMD-DRRMS-SS-REP-26-07-00622-S")]/w:pPr/w:ind')->length)->toBe(0)
        ->and($xpath->query('//w:p[contains(., "DRN: CARAGA-FO-DRMD-DRRMS-SS-REP-26-07-00622-S")]//w:br')->length)->toBe(0)
        ->and($xpath->query('//w:sectPr/w:pgMar[@w:top="1296"]')->length)->toBeGreaterThanOrEqual(1)
        ->and($xpath->query('//w:r[w:t[contains(., "Mayor Requester")]]/w:rPr/w:b')->length)->toBeGreaterThan(0)
        ->and($xpath->query('//w:r[w:t[contains(., "Mayor Requester")]]/w:rPr/w:i')->length)->toBeGreaterThan(0)
        ->and($xpath->query('//w:p[contains(., "This is in reference")]/w:pPr/w:spacing[@w:line="276" and not(@w:after="0")]')->length)->toBeGreaterThan(0)
        ->and($xpath->query('//w:p[contains(., "JSP/AAA/JLM/1628")]/w:r[w:t][1]/w:rPr/w:i')->length)->toBeGreaterThan(0)
        ->and($xpath->query('//w:p[contains(., "JSP/AAA/JLM/1628")]/w:r[w:t][1]/w:rPr/w:rFonts[@w:ascii="Arial"]')->length)->toBeGreaterThan(0)
        ->and($xpath->query('//w:p[contains(., "JSP/AAA/JLM/1628")]/w:r[w:t][1]/w:rPr/w:sz[@w:val="16"]')->length)->toBeGreaterThan(0);

    $pdfResponse = $this->actingAs($user)
        ->get("/requests/{$request->id}/response-letter-pdf?inline=1")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    $pdfContents = file_get_contents($pdfResponse->baseResponse->getFile()->getPathname());
    expect(substr($pdfContents, 0, 4))->toBe('%PDF')
        ->and(preg_match_all('/\/Type\s*\/Page\b/', $pdfContents))->toBe(2);
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

    $pdf = Pdf::loadView('documents.assessment', [
        'request' => $request->load(['items.sourceWarehouse', 'assessmentType', 'incident', 'encoder']),
        'pageMargin' => 18,
    ])->setPaper('a4', 'portrait');
    $pdf->render();

    expect($pdf->getDomPDF()->getCanvas()->get_page_count())->toBe(1);
});
