<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SSOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function __construct(
        private readonly SSOAuthService $sso,
        private readonly AuditLogger $audit,
    ) {}

    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt([...$credentials, 'is_active' => true], $request->boolean('remember'))) {
            $user = User::where('email', $credentials['email'])->first();
            $this->audit->log('auth.login_failed', $user, [], [
                'email' => $credentials['email'],
                'reason' => 'invalid_credentials_or_inactive',
            ], $user?->id);

            throw ValidationException::withMessages([
                'email' => 'The provided credentials do not match an active user.',
            ]);
        }

        $request->session()->regenerate();
        $this->audit->log('auth.login', Auth::user(), [], [
            'method' => 'password',
            'remember' => $request->boolean('remember'),
        ], Auth::id());

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $this->audit->log('auth.logout', $user, [], [
            'reason' => $request->string('reason')->toString() ?: 'manual',
        ], $user?->id);

        $accessToken = $request->session()->get('idp_access_token');

        if ($accessToken) {
            try {
                $this->sso->logout($accessToken, $request->boolean('logout_all'));
            } catch (\Throwable $exception) {
                Log::warning('SSO logout failed; clearing local session anyway.', [
                    'user_id' => Auth::id(),
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
