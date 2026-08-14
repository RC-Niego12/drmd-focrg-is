<?php

use App\Models\AssistanceRequest;
use App\Models\User;
use App\Services\WorkflowNotificationService;
use App\Support\LguFniProcessingStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function makeLguFniRealtimeUser(string $email, string $psgc = '1606727000'): User
{
    $lguRole = Role::firstOrCreate(['name' => 'LGU', 'guard_name' => 'web']);
    $lgu = User::create([
        'name' => 'LGU FNI Realtime',
        'email' => $email,
        'password' => Hash::make('password'),
        'lgu_psgc_code' => $psgc,
        'lgu_name' => 'TUBOD',
        'lgu_level' => 'MLGU',
        'access_status' => 'approved',
        'access_approved_at' => now(),
        'is_active' => true,
        'office' => 'LGU',
        'position' => 'DROMIC Focal',
    ]);
    $lgu->syncRoles([$lguRole]);

    return $lgu;
}

function enableRealtimeForTest(): void
{
    config([
        'realtime.enabled' => true,
        'realtime.internal_url' => 'http://127.0.0.1:6002',
        'realtime.public_url' => 'http://127.0.0.1:6001',
        'realtime.secret' => 'test-realtime-secret',
        'realtime.publish_timeout_seconds' => 1,
    ]);

    Http::fake([
        'http://127.0.0.1:6002/publish' => Http::response(['success' => true]),
    ]);
}

it('broadcasts lgu.fni.processing.updated when an advance response letter is released', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    enableRealtimeForTest();

    $lgu = makeLguFniRealtimeUser('lgu-fni-advance@example.test');
    $pdrc = User::where('email', 'drrs@example.test')->firstOrFail();

    $record = AssistanceRequest::create([
        'reference_number' => 'REQ-LGU-FNI-ADVANCE',
        'requesting_agency' => 'Municipality of Tubod',
        'requester' => 'LGU Officer',
        'date_requested' => '2026-08-02',
        'status' => 'under_review',
        'assessment_status' => 'draft',
        'assessment_acted_by' => $pdrc->id,
        'response_drn' => 'FOCARAGA-DRMD-RL-26-08-0099',
        'endorsed_to_drrs' => true,
        'lgu_psgc_code' => '1606727000',
        'lgu_submitted_by' => $lgu->id,
    ]);

    $this->actingAs($pdrc)
        ->postJson(route('requests.epirma.forward', $record))
        ->assertOk()
        ->assertJsonPath('success', true);

    Http::assertSent(fn ($request): bool => $request->url() === 'http://127.0.0.1:6002/publish'
        && $request['event'] === 'lgu.fni.processing.updated'
        && ($request['payload']['request_id'] ?? null) === $record->id
        && ($request['payload']['reason'] ?? null) === 'advance_response_letter'
        && ($request['payload']['processing_key'] ?? null) === LguFniProcessingStatus::KEY_AWAITING_SIGNED_RESPONSE_LETTER
        && ($request['payload']['has_advance'] ?? null) === true
        && collect($request['rooms'] ?? [])->contains('user:'.$lgu->id));
});

it('broadcasts lgu.fni.processing.updated when LGU acknowledges an advance response letter', function (): void {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    enableRealtimeForTest();

    $lgu = makeLguFniRealtimeUser('lgu-fni-ack@example.test');
    $advancePath = 'lgu_response_letters/advance-LGU-REQ-FNI-ACK.pdf';
    Storage::disk('public')->put($advancePath, "%PDF-1.4\n% advance\n%%EOF\n");

    $record = AssistanceRequest::create([
        'reference_number' => 'LGU-REQ-FNI-ACK',
        'requesting_agency' => 'Municipality of Tubod',
        'requester' => 'LGU Officer',
        'date_requested' => '2026-08-02',
        'status' => 'under_review',
        'lgu_psgc_code' => '1606727000',
        'lgu_response_letter_advance_path' => $advancePath,
        'lgu_response_letter_advance_name' => 'advance.pdf',
        'lgu_response_letter_advance_sent_at' => now()->subHour(),
    ]);

    $this->actingAs($lgu)
        ->postJson(route('lgu.response-letters.acknowledge', $record), ['kind' => 'advance'])
        ->assertOk()
        ->assertJsonPath('success', true);

    Http::assertSent(fn ($request): bool => $request->url() === 'http://127.0.0.1:6002/publish'
        && $request['event'] === 'lgu.fni.processing.updated'
        && ($request['payload']['request_id'] ?? null) === $record->id
        && ($request['payload']['reason'] ?? null) === 'advance_acknowledged'
        && ($request['payload']['processing_key'] ?? null) === LguFniProcessingStatus::KEY_AWAITING_SIGNED_RESPONSE_LETTER
        && filled($request['payload']['advance_acked_at'] ?? null)
        && collect($request['rooms'] ?? [])->contains('user:'.$lgu->id));
});

it('broadcasts lgu.fni.processing.updated to LGU recipients via the notification helper', function (): void {
    $this->seed(DatabaseSeeder::class);
    enableRealtimeForTest();

    $lgu = makeLguFniRealtimeUser('lgu-fni-helper@example.test');

    $record = AssistanceRequest::create([
        'reference_number' => 'LGU-REQ-FNI-HELPER',
        'requesting_agency' => 'Municipality of Tubod',
        'requester' => 'LGU Officer',
        'date_requested' => '2026-08-02',
        'status' => 'acted',
        'lgu_psgc_code' => '1606727000',
        'epirma_response_letter_signed_at' => now(),
        'lgu_response_letter_sent_at' => now(),
    ]);

    app(WorkflowNotificationService::class)->notifyLguSignedResponseLetter($record->fresh());

    Http::assertSent(fn ($request): bool => $request->url() === 'http://127.0.0.1:6002/publish'
        && $request['event'] === 'lgu.fni.processing.updated'
        && ($request['payload']['reason'] ?? null) === 'signed_response_letter'
        && ($request['payload']['processing_key'] ?? null) === LguFniProcessingStatus::KEY_ACTED
        && ($request['payload']['has_signed'] ?? null) === true
        && collect($request['rooms'] ?? [])->contains('user:'.$lgu->id));
});
