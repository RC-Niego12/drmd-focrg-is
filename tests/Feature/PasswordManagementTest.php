<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('lets authenticated users update their local password', function (): void {
    $user = User::create([
        'name' => 'LGU Test User',
        'email' => 'mlgu-test-sdn@dromis.test',
        'password' => Hash::make('password'),
        'is_active' => true,
        'access_status' => 'approved',
    ]);

    $this->actingAs($user)
        ->patch(route('settings.password'), [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Password updated successfully.');

    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
});

it('sends password reset links without exposing whether an account exists', function (): void {
    Notification::fake();

    $user = User::create([
        'name' => 'PLGU ADN',
        'email' => 'plgu-adn@dromis.test',
        'password' => Hash::make('password'),
        'is_active' => true,
        'access_status' => 'approved',
    ]);

    $this->post(route('password.email'), [
        'email' => $user->email,
    ])
        ->assertRedirect()
        ->assertSessionHas('success', 'If that email exists in DROMIS, a password reset link has been sent.');

    Notification::assertSentTo($user, ResetPassword::class);

    $this->post(route('password.email'), [
        'email' => 'missing-lgu@dromis.test',
    ])
        ->assertRedirect()
        ->assertSessionHas('success', 'If that email exists in DROMIS, a password reset link has been sent.');
});

it('keeps employee and LGU password logins on their correct portals', function (): void {
    $lguRole = Role::firstOrCreate(['name' => 'LGU', 'guard_name' => 'web']);

    $employee = User::create([
        'name' => 'DSWD Employee',
        'email' => 'employee@example.test',
        'password' => Hash::make('password'),
        'is_active' => true,
        'access_status' => 'approved',
    ]);

    $lgu = User::create([
        'name' => 'MLGU Loreto',
        'username' => 'mlgu-loreto-pdi',
        'email' => 'mlgu-loreto-pdi@dromis.test',
        'password' => Hash::make('password'),
        'is_active' => true,
        'access_status' => 'approved',
        'lgu_level' => 'MLGU',
        'lgu_psgc_code' => '1608505000',
    ]);
    $lgu->assignRole($lguRole);

    $this->get('/login-lgu')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auth/Login')
            ->where('loginAudience', 'lgu'));

    $this->post('/login', [
        'email' => $lgu->email,
        'password' => 'password',
    ])
        ->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->post('/login-lgu', [
        'username' => $employee->username,
        'password' => 'password',
    ])
        ->assertSessionHasErrors('username');
    $this->assertGuest();

    $this->post('/login-lgu', [
        'username' => $lgu->username,
        'password' => 'password',
    ])
        ->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($lgu);
});
