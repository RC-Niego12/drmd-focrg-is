<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\LguPersonnelAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LguCredentialSetupController extends Controller
{
    public function edit(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        if (! $user->must_change_password) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/LguCredentialSetup', [
            'username' => $user->username,
            'name' => $user->name,
            'role' => $user->lgu_directory_role,
            'lgu_name' => $user->lgu_name,
            'password_is_default' => (bool) $user->password_is_default,
            'default_password' => $user->password_is_default
                ? LguPersonnelAccountService::DEFAULT_PASSWORD
                : null,
        ]);
    }

    public function retain(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && $user->must_change_password, 403);

        $user->forceFill([
            'must_change_password' => false,
        ])->save();

        $audit->log('user.lgu_credentials_retained', $user, [], [
            'username' => $user->username,
        ], $user->id);

        return redirect()->route('dashboard')->with(
            'success',
            'You kept your current LGU login credentials. You can change them anytime from your profile.',
        );
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && $user->must_change_password, 403);

        $validated = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:120', 'regex:/^[a-z0-9._-]+$/'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $username = mb_strtolower(trim($validated['username']));
        $conflict = \App\Models\User::withTrashed()
            ->where('username', $username)
            ->whereKeyNot($user->id)
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'username' => 'That username is already in use.',
            ]);
        }

        $user->forceFill([
            'username' => $username,
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
            'password_is_default' => $validated['password'] === LguPersonnelAccountService::DEFAULT_PASSWORD,
        ])->save();

        foreach (app(LguPersonnelAccountService::class)->findAllLinkedPersonnel($user) as $linked) {
            $linked['model']->forceFill([
                'login_username' => $username,
            ])->save();
        }

        $audit->log('user.lgu_credentials_changed', $user, [], [
            'username' => $username,
        ], $user->id);

        return redirect()->route('dashboard')->with('success', 'Your LGU login credentials were updated.');
    }
}
