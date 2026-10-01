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
        return Inertia::render('Auth/Login', [
            'loginAudience' => 'employee',
        ]);
    }

    public function createLgu(): Response
    {
        return Inertia::render('Auth/Login', [
            'loginAudience' => 'lgu',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->authenticate($request, 'employee');
    }

    public function storeLgu(Request $request): RedirectResponse
    {
        return $this->authenticate($request, 'lgu');
    }

    private function authenticate(Request $request, string $audience): RedirectResponse
    {
        $credentials = $request->validate($audience === 'lgu'
            ? [
                'username' => ['required', 'string'],
                'password' => ['required', 'string'],
            ]
            : [
                'email' => ['required', 'string'],
                'password' => ['required', 'string'],
            ]);

        $identifierField = $audience === 'lgu' ? 'username' : 'email';
        $identifier = trim((string) ($credentials[$identifierField] ?? ''));
        $attempted = $this->attemptCredentials($request, $identifier, $credentials['password'], $audience);

        if (! $attempted) {
            $user = User::where('username', $identifier)
                ->orWhere('email', $identifier)
                ->first();
            $this->audit->log('auth.login_failed', $user, [], [
                'username' => $request->string('username')->toString(),
                'email' => $request->string('email')->toString(),
                'reason' => 'invalid_credentials_or_inactive',
            ], $user?->id);

            throw ValidationException::withMessages([
                $identifierField => 'The provided credentials do not match an active user.',
            ]);
        }

        $user = Auth::user();

        if ($audience === 'lgu' && ! $this->isLguUser($user)) {
            $this->rejectAudience($request, $user, 'employee_on_lgu_portal');

            throw ValidationException::withMessages([
                'username' => 'DSWD employee accounts must sign in through the regular DROMIS login or Caraga Connect SSO.',
            ]);
        }

        if ($audience === 'lgu' && $user && ! $user->canAccessLguPortalLogin()) {
            $this->rejectAudience($request, $user, 'lgu_not_linked_to_profile_personnel');

            throw ValidationException::withMessages([
                'username' => 'Only LGU profile personnel can sign in. Use an LCE, LSWDO, LDRRMO, or alternate account to open the LGU profile and create your personal login username, or ask them to do it for you.',
            ]);
        }

        if ($audience !== 'lgu' && $this->isLguUser($user)) {
            $this->rejectAudience($request, $user, 'lgu_on_employee_portal');

            throw ValidationException::withMessages([
                'email' => 'LGU accounts must sign in through the LGU portal.',
            ]);
        }

        $request->session()->regenerate();
        $this->audit->log('auth.login', $user, [], [
            'method' => 'password',
            'audience' => $audience,
            'remember' => $request->boolean('remember'),
        ], Auth::id());

        if (! (bool) config('services.cc_idp.bypass_mfa', true) && $user?->mfa_enabled) {
            $request->session()->put('mfa_required', true);

            return redirect()->route('mfa.verify');
        }

        if ($user?->must_change_password) {
            return redirect()->route('lgu.credentials.edit');
        }

        $home = $audience === 'lgu'
            ? route('dashboard')
            : ($user?->hasRole('OCD Caraga') ? route('ocd.alerts.index') : route('dashboard'));

        return $audience === 'lgu'
            ? redirect()->to($home)
            : redirect()->intended($home);
    }

    private function attemptCredentials(Request $request, string $identifier, string $password, string $audience): bool
    {
        $attempts = $audience === 'lgu'
            ? [
                ['username' => $identifier, 'password' => $password, 'is_active' => true],
                ['email' => $identifier, 'password' => $password, 'is_active' => true],
            ]
            : [
                ['email' => $identifier, 'password' => $password, 'is_active' => true],
                ['username' => $identifier, 'password' => $password, 'is_active' => true],
            ];

        foreach ($attempts as $attemptCredentials) {
            if (Auth::attempt($attemptCredentials, $request->boolean('remember'))) {
                return true;
            }
        }

        return false;
    }

    private function isLguUser(?User $user): bool
    {
        return (bool) ($user?->hasRole('LGU') || filled($user?->lgu_psgc_code) || filled($user?->lgu_level));
    }

    private function rejectAudience(Request $request, ?User $user, string $reason): void
    {
        $this->audit->log('auth.login_failed', $user, [], [
            'email' => $request->string('email')->toString(),
            'username' => $request->string('username')->toString(),
            'reason' => $reason,
        ], $user?->id);

        Auth::guard('web')->logout();
        $request->session()->regenerateToken();
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

        if ($request->string('reason')->toString() === 'inactivity') {
            return redirect()->route('login')->with(
                'error',
                'You were signed out after a period of inactivity. Sign in again to continue your work.',
            );
        }

        return redirect()->route('login');
    }
}
