<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ssoEmployee(array $attributes = []): User
{
    return User::create([
        'name' => 'Caraga Connect Employee',
        'email' => 'cc.employee@example.test',
        'username' => 'cc.employee',
        'sso_sub' => 'cc-employee-001',
        'password' => 'password',
        'office' => 'DRRS',
        'is_active' => true,
        'access_status' => 'pending',
        ...$attributes,
    ]);
}

it('notifies super admins and completes an approved access decision with reassignment', function (): void {
    $this->seed(DatabaseSeeder::class);
    $admin = User::where('email', 'superadmin@example.test')->firstOrFail();
    $employee = ssoEmployee();
    $employee->assignRole('guest');

    $this->actingAs($employee)
        ->post(route('access.request.store'), ['requested_role' => 'RROS'])
        ->assertRedirect();

    expect($employee->fresh()->access_status)->toBe('pending')
        ->and($employee->fresh()->requested_role)->toBe('RROS')
        ->and($admin->fresh()->unreadNotifications)->toHaveCount(1)
        ->and($admin->fresh()->unreadNotifications->first()->data['kind'])->toBe('access_requested');

    $this->actingAs($admin)
        ->getJson(route('notifications.index'))
        ->assertOk()
        ->assertJsonPath('action_required_count', 1);

    $this->actingAs($admin)
        ->post(route('access-management.decision', $employee), [
            'action' => 'approve',
            'role' => 'DRRS',
            'response_message' => 'Assigned to DRRS based on your current office.',
        ])
        ->assertRedirect();

    $employee->refresh();
    expect($employee->access_status)->toBe('approved')
        ->and($employee->requested_role)->toBeNull()
        ->and($employee->hasRole('DRRS'))->toBeTrue()
        ->and($employee->unreadNotifications)->toHaveCount(1)
        ->and($employee->unreadNotifications->first()->data['status'])->toBe('approved');

    $this->actingAs($admin)
        ->getJson(route('notifications.index'))
        ->assertOk()
        ->assertJsonPath('action_required_count', 0);

    $this->actingAs($employee)
        ->getJson(route('notifications.index'))
        ->assertOk()
        ->assertJsonPath('access.status', 'approved')
        ->assertJsonPath('access.assigned_role', 'DRRS')
        ->assertJsonPath('access.home_url', route('dashboard'));
});

it('prevents self-requesting privileged roles and sends rejection reasons', function (): void {
    $this->seed(DatabaseSeeder::class);
    $admin = User::where('email', 'superadmin@example.test')->firstOrFail();
    $employee = ssoEmployee(['email' => 'denied.employee@example.test', 'username' => 'denied.employee', 'sso_sub' => 'cc-employee-002']);
    $employee->assignRole('guest');

    $this->actingAs($employee)
        ->post(route('access.request.store'), ['requested_role' => 'Super Admin'])
        ->assertSessionHasErrors('requested_role');

    $this->actingAs($employee)
        ->post(route('access.request.store'), ['requested_role' => 'DRIMS'])
        ->assertRedirect();

    $this->actingAs($admin)
        ->post(route('access-management.decision', $employee), [
            'action' => 'deny',
            'response_message' => 'Please request the user level matching your official assignment.',
        ])
        ->assertRedirect();

    $employee->refresh();
    expect($employee->access_status)->toBe('denied')
        ->and($employee->access_response_message)->toContain('official assignment')
        ->and($employee->hasRole('guest'))->toBeTrue()
        ->and($employee->unreadNotifications->first()->data['status'])->toBe('denied');
});

it('assigns super admin only to active Caraga Connect SSO employees', function (): void {
    $this->seed(DatabaseSeeder::class);
    $admin = User::where('email', 'superadmin@example.test')->firstOrFail();
    $employee = ssoEmployee(['email' => 'future.admin@example.test', 'username' => 'future.admin', 'sso_sub' => 'cc-admin-001']);
    $employee->assignRole('guest');

    $this->actingAs($admin)
        ->post(route('access-management.super-admin'), ['user_id' => $employee->id])
        ->assertRedirect();

    expect($employee->fresh()->hasRole('Super Admin'))->toBeTrue()
        ->and($employee->fresh()->access_status)->toBe('approved');

    $localUser = User::where('email', 'rros@example.test')->firstOrFail();
    $this->actingAs($admin)
        ->post(route('access-management.super-admin'), ['user_id' => $localUser->id])
        ->assertNotFound();
});

it('blocks generic super admin escalation and protects the last administrator', function (): void {
    $this->seed(DatabaseSeeder::class);
    $admin = User::where('email', 'superadmin@example.test')->firstOrFail();
    $localUser = User::where('email', 'rros@example.test')->firstOrFail();

    $this->actingAs($admin)
        ->patch(route('access-management.update', $localUser), [
            'name' => $localUser->name,
            'role' => 'Super Admin',
            'access_status' => 'approved',
            'office' => $localUser->office,
            'position' => $localUser->position,
            'designation' => $localUser->designation,
            'is_active' => true,
        ])
        ->assertSessionHasErrors('role');

    $this->actingAs($admin)
        ->patch(route('access-management.update', $admin), [
            'name' => $admin->name,
            'role' => 'RROS',
            'access_status' => 'approved',
            'office' => $admin->office,
            'position' => $admin->position,
            'designation' => $admin->designation,
            'is_active' => true,
        ])
        ->assertSessionHasErrors('role');

    expect($admin->fresh()->hasRole('Super Admin'))->toBeTrue()
        ->and($localUser->fresh()->hasRole('Super Admin'))->toBeFalse();
});

it('allows super admins to archive and restore users', function (): void {
    $this->seed(DatabaseSeeder::class);
    $admin = User::where('email', 'superadmin@example.test')->firstOrFail();
    $employee = ssoEmployee(['email' => 'archive.employee@example.test', 'username' => 'archive.employee', 'sso_sub' => 'cc-archive-001']);
    $employee->assignRole('guest');

    $this->actingAs($admin)
        ->delete(route('access-management.destroy', $employee))
        ->assertRedirect();

    $this->assertSoftDeleted('users', ['id' => $employee->id]);

    $this->actingAs($admin)
        ->post(route('access-management.restore', $employee->id))
        ->assertRedirect();

    $employee->refresh();
    expect($employee->deleted_at)->toBeNull()
        ->and($employee->is_active)->toBeTrue();
});

it('requires confirmation before permanently deleting an archived user', function (): void {
    $this->seed(DatabaseSeeder::class);
    $admin = User::where('email', 'superadmin@example.test')->firstOrFail();
    $employee = ssoEmployee(['email' => 'delete.employee@example.test', 'username' => 'delete.employee', 'sso_sub' => 'cc-delete-001']);
    $employee->assignRole('guest');
    $employee->delete();

    $this->actingAs($admin)
        ->delete(route('access-management.force-delete', $employee->id), [
            'confirmation' => 'wrong@example.test',
        ])
        ->assertSessionHasErrors('confirmation');

    $this->actingAs($admin)
        ->delete(route('access-management.force-delete', $employee->id), [
            'confirmation' => $employee->email,
        ])
        ->assertRedirect();

    $this->assertDatabaseMissing('users', ['id' => $employee->id]);
});

it('prevents deleting yourself or the last active super admin', function (): void {
    $this->seed(DatabaseSeeder::class);
    $admin = User::where('email', 'superadmin@example.test')->firstOrFail();

    $this->actingAs($admin)
        ->delete(route('access-management.destroy', $admin))
        ->assertSessionHasErrors('user');

    expect($admin->fresh()->trashed())->toBeFalse();
});
