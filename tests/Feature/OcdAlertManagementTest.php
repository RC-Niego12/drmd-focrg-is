<?php

use App\Models\RegionalAlert;
use App\Models\RegionalAlertRecipient;
use App\Models\User;
use App\Services\RegionalAlertNotificationService;
use App\Notifications\WorkflowNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $permission = Permission::firstOrCreate(['name' => 'manage regional alerts', 'guard_name' => 'web']);
    $this->ocdRole = Role::firstOrCreate(['name' => 'OCD Caraga', 'guard_name' => 'web']);
    $this->ocdRole->givePermissionTo($permission);
    $this->lguRole = Role::firstOrCreate(['name' => 'LGU', 'guard_name' => 'web']);
});

it('allows OCD Caraga to issue a regional alert and notifies active LGUs', function (): void {
    Notification::fake();
    $ocd = User::create([
        'name' => 'OCD Caraga Tester',
        'email' => 'ocd-tester@example.test',
        'password' => Hash::make('password'),
        'is_active' => true,
        'access_status' => 'approved',
    ]);
    $ocd->assignRole($this->ocdRole);
    $lgu = User::create([
        'name' => 'LGU Tester',
        'email' => 'lgu-tester@example.test',
        'password' => Hash::make('password'),
        'is_active' => true,
        'access_status' => 'approved',
    ]);
    $lgu->assignRole($this->lguRole);
    $drimsRole = Role::firstOrCreate(['name' => 'DRIMS', 'guard_name' => 'web']);
    $drmd = User::create([
        'name' => 'DRIMS Tester',
        'email' => 'drims-alert@example.test',
        'password' => Hash::make('password'),
        'is_active' => true,
        'access_status' => 'approved',
    ]);
    $drmd->assignRole($drimsRole);
    $drimsRole->givePermissionTo(Permission::firstOrCreate([
        'name' => 'view regional alert acknowledgements',
        'guard_name' => 'web',
    ]));
    $qrt = User::create([
        'name' => 'QRT Tester',
        'email' => 'qrt-alert@example.test',
        'password' => Hash::make('password'),
        'office' => 'Quick Response Team',
        'is_active' => true,
        'access_status' => 'approved',
    ]);
    $unrelated = User::create([
        'name' => 'Unrelated Tester',
        'email' => 'unrelated-alert@example.test',
        'password' => Hash::make('password'),
        'office' => 'Administrative Division',
        'is_active' => true,
        'access_status' => 'approved',
    ]);

    $this->actingAs($ocd)->post(route('ocd.alerts.store'), [
        'alert_level' => 'blue',
        'incident_name' => 'Test Weather Disturbance',
        'coverage' => 'Caraga Region',
        'reason' => 'Heightened monitoring and twice-daily reporting are required.',
        'effective_at' => now()->subMinute()->format('Y-m-d H:i:s'),
    ])->assertSessionHasNoErrors();

    expect(RegionalAlert::query()->where('alert_level', 'blue')->exists())->toBeTrue();
    expect(RegionalAlertRecipient::query()->where('recipient_category', 'lgu')->where('user_id', $lgu->id)->exists())->toBeTrue()
        ->and(RegionalAlertRecipient::query()->where('recipient_category', 'dswd')->where('user_id', $drmd->id)->exists())->toBeTrue()
        ->and(RegionalAlertRecipient::query()->where('recipient_category', 'dswd')->where('user_id', $qrt->id)->exists())->toBeTrue();
    Notification::assertSentTo($lgu, WorkflowNotification::class);
    Notification::assertSentTo($lgu, WorkflowNotification::class, fn ($notification) => str_contains($notification->toArray($lgu)['url'], '/lgu/dromic-sitrep'));
    Notification::assertSentTo($drmd, WorkflowNotification::class, fn ($notification) => str_contains($notification->toArray($drmd)['url'], '/alert-acknowledgments'));
    Notification::assertSentTo($qrt, WorkflowNotification::class);
    Notification::assertNotSentTo($unrelated, WorkflowNotification::class);
});

it('updates the OCD agency profile and stores its logo', function (): void {
    Storage::fake('public');
    $ocd = User::create([
        'name' => 'OCD Caraga Profile Tester',
        'email' => 'ocd-profile@example.test',
        'password' => Hash::make('password'),
        'is_active' => true,
        'access_status' => 'approved',
    ]);
    $ocd->assignRole($this->ocdRole);

    $this->actingAs($ocd)->post(route('ocd.profile.update'), [
        'agency_name' => 'Office of Civil Defense Caraga',
        'acronym' => 'OCD Caraga',
        'email' => 'caraga@ocd.gov.ph',
        'logo' => UploadedFile::fake()->image('ocd.png'),
    ])->assertSessionHasNoErrors();

    $profile = $ocd->fresh()->agencyProfile;
    expect($profile->agency_name)->toBe('Office of Civil Defense Caraga');
    Storage::disk('public')->assertExists($profile->logo_path);
});

it('accepts the OCD username on the regular login and opens regional alerts', function (): void {
    $ocd = User::create([
        'name' => 'OCD Caraga Login Tester',
        'email' => 'ocd-login@example.test',
        'username' => 'ocd-caraga-test',
        'password' => Hash::make('password'),
        'is_active' => true,
        'access_status' => 'approved',
    ]);
    $ocd->assignRole($this->ocdRole);

    $this->post('/login', [
        'email' => 'ocd-caraga-test',
        'password' => 'password',
    ])->assertRedirect(route('ocd.alerts.index'));

    $this->assertAuthenticatedAs($ocd);
    $this->get(route('ocd.alerts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Ocd/AlertManagement'));
});

it('keeps White Alert as the regional default', function (): void {
    $default = app(\App\Services\RegionalAlertNotificationService::class)->ensureDefaultWhite();

    expect($default->alert_level)->toBe('white')
        ->and($default->coverage)->toBe('Caraga Region');
});

it('lets each recipient acknowledge the newest OCD alert and clears superseded alert prompts', function (): void {
    $user = User::create([
        'name' => 'LGU Alert Recipient',
        'email' => 'lgu-alert-recipient@example.test',
        'password' => Hash::make('password'),
        'is_active' => true,
        'access_status' => 'approved',
    ]);
    $user->assignRole($this->lguRole);
    $ocd = User::create([
        'name' => 'OCD Alert Setter',
        'email' => 'ocd-alert-setter@example.test',
        'password' => Hash::make('password'),
        'is_active' => true,
        'access_status' => 'approved',
    ]);
    $ocd->assignRole($this->ocdRole);
    $service = app(RegionalAlertNotificationService::class);
    $previous = null;
    foreach (['blue', 'red'] as $level) {
        $alert = RegionalAlert::create([
            'set_by' => $ocd->id,
            'alert_level' => $level,
            'incident_name' => 'Test Incident',
            'coverage' => 'Caraga Region',
            'reason' => 'Operational monitoring is required.',
            'effective_at' => now(),
        ]);
        $service->notifyAlertChanged($alert, $previous);
        $previous = $alert;
    }

    $newest = $user->notifications()
        ->where('data->meta->alert_id', $previous->id)
        ->firstOrFail();
    $newest->markAsRead();
    $center = app(\App\Services\AccessNotificationCenter::class)->forUser($user->fresh());
    expect(data_get($center, 'regional_alert_prompt.id'))->toBe($newest->id);

    $this->actingAs($user)
        ->patch(route('notifications.regional-alert.acknowledge', $newest->id))
        ->assertNoContent();

    expect($user->fresh()->unreadNotifications()
        ->where('data->action_key', 'ocd_alert_changed')
        ->count())->toBe(0)
        ->and(RegionalAlertRecipient::query()->where('user_id', $user->id)->whereNotNull('acknowledged_at')->count())->toBe(1)
        ->and(RegionalAlertRecipient::query()->where('user_id', $user->id)->whereNotNull('superseded_at')->count())->toBe(1);
    $updatedCenter = app(\App\Services\AccessNotificationCenter::class)->forUser($user->fresh());
    $updatedNotification = collect($updatedCenter['notifications'])->firstWhere('id', $newest->id);
    expect($updatedCenter['unread_count'])->toBe(0)
        ->and($updatedNotification['regional_alert_acknowledged_at'])->not->toBeNull()
        ->and($updatedCenter['regional_alert_prompt'])->toBeNull();
});

it('shows OCD and authorized DSWD users the consolidated acknowledgment board', function (): void {
    $permission = Permission::firstOrCreate(['name' => 'view regional alert acknowledgements', 'guard_name' => 'web']);
    $this->ocdRole->givePermissionTo($permission);
    $ocd = User::create([
        'name' => 'OCD Board Viewer',
        'email' => 'ocd-board@example.test',
        'password' => Hash::make('password'),
        'is_active' => true,
        'access_status' => 'approved',
    ]);
    $ocd->assignRole($this->ocdRole);
    $alert = RegionalAlert::create([
        'set_by' => $ocd->id,
        'alert_level' => 'white',
        'coverage' => 'Caraga Region',
        'reason' => 'Routine monitoring.',
        'effective_at' => now(),
    ]);
    RegionalAlertRecipient::create([
        'regional_alert_id' => $alert->id,
        'user_id' => $ocd->id,
        'recipient_category' => 'dswd',
        'recipient_name' => 'DRMD Test Recipient',
        'recipient_role' => 'DRMD',
        'office' => 'DRMD',
        'notified_at' => now()->subMinutes(5),
        'acknowledged_at' => now(),
        'acknowledgement_method' => 'attention_modal',
    ]);

    $this->actingAs($ocd)
        ->get(route('regional-alert-acknowledgements.index', ['alert_id' => $alert->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Ocd/AlertAcknowledgements')
            ->where('summary.all.total', 1)
            ->where('summary.all.acknowledged', 1)
            ->where('recipients.0.recipient_name', 'DRMD Test Recipient'));

    $this->actingAs($ocd)
        ->get(route('alert-acknowledgments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Ocd/AlertAcknowledgements')
            ->where('selectedAlert.id', $alert->id)
            ->where('summary.all.total', 1));

    $this->actingAs($ocd)
        ->getJson(route('regional-alert-acknowledgements.index', ['alert_id' => $alert->id]))
        ->assertOk()
        ->assertJsonPath('summary.all.total', 1)
        ->assertJsonPath('summary.all.acknowledged', 1)
        ->assertJsonPath('recipients.0.recipient_name', 'DRMD Test Recipient');
});
