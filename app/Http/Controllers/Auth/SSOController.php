<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SSOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Throwable;

class SSOController extends Controller
{
    public function __construct(
        private readonly SSOAuthService $sso,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Step 1: start SSO — redirect browser to Provider /oauth/authorize
     */
    public function login(Request $request): RedirectResponse
    {
        try {
            $this->ensureConfigured();

            $authorizeUrl = $this->sso->buildAuthorizeRedirect();
            if ($problem = $this->sso->authorizationProblem($authorizeUrl)) {
                Log::warning('Caraga Connect rejected authorization preflight.', [
                    'authorizeUrl' => $authorizeUrl,
                    'message' => $problem,
                ]);

                return $this->ssoFailure($problem);
            }

            Log::info('SSO Login - Redirecting', [
                'authorizeUrl' => $authorizeUrl,
                'sessionId' => $request->session()->getId(),
            ]);

            return redirect()->away($authorizeUrl);
        } catch (Throwable $exception) {
            Log::warning('Caraga Connect authorization could not start.', ['exception' => $exception]);

            return redirect()->route('login')->with('error', 'Caraga Connect is temporarily unavailable. Please try again or use your local account.');
        }
    }

    /**
     * Step 2: callback — verify state, exchange code, fetch profile, log in
     */
    public function callback(Request $request): RedirectResponse
    {
        try {
            if ($err = $request->query('error')) {
                throw ValidationException::withMessages(['sso' => 'Provider error: '.$err]);
            }

            $state = $request->query('state');
            $code = $request->query('code');

            if (! $state || ! $code) {
                throw ValidationException::withMessages(['sso' => 'Missing authorization response.']);
            }

            try {
                $codeVerifier = $this->sso->consumePkceVerifier((string) $state);
            } catch (RuntimeException) {
                throw ValidationException::withMessages(['sso' => 'Invalid SSO state. Start Sign in with Caraga Connect again (do not reuse an old browser tab).']);
            }

            Log::info('SSO Callback - PKCE state accepted', [
                'sessionId' => $request->session()->getId(),
                'state' => $state,
            ]);

            $tokens = $this->sso->exchangeCodeForTokens((string) $code, $codeVerifier);
            $accessToken = $tokens['access_token'] ?? null;

            if (! $accessToken) {
                throw ValidationException::withMessages(['sso' => 'No access token returned.']);
            }

            $ssoLookupProfile = $this->sso->fetchUserInfo($accessToken);
            $ssoMappedProfile = $this->sso->mapProfileToUserFields($ssoLookupProfile);
            $username = $ssoMappedProfile['username'];
            $sub = $ssoMappedProfile['sso_sub'];
            $name = $ssoMappedProfile['name'];
            $email = $ssoMappedProfile['email'];
            $idNumber = $ssoMappedProfile['id_number'];

            if (! $sub) {
                throw ValidationException::withMessages(['sso' => 'Provider did not return a subject (sub).']);
            }

            $locator = filled($username) ? ['username' => $username] : ['sso_sub' => $sub];
            $existingUser = User::query()->where($locator)->first();
            $wasCreated = ! $existingUser;
            $existingProfilePayload = is_array($existingUser?->sso_profile_payload) ? $existingUser->sso_profile_payload : [];

            $user = User::updateOrCreate(
                $locator,
                [
                    'sso_sub' => $sub,
                    'name' => $name ?: 'SSO User',
                    'username' => $username ?: $existingUser?->username,
                    'email' => $email ?: $existingUser?->email ?: (($username ?: Str::slug($sub)).'@caraga-connect.local'),
                    'id_number' => $idNumber ?: $existingUser?->id_number,
                    'office' => $existingUser?->office ?: config('services.cc_idp.default_office'),
                    'position' => $existingUser?->position,
                    'designation' => $existingUser?->designation,
                    'area_of_assignment' => $ssoMappedProfile['area_of_assignment'] ?: $existingUser?->area_of_assignment,
                    'employment_status' => $ssoMappedProfile['employment_status'] ?: $existingUser?->employment_status,
                    'sso_profile_payload' => [
                        ...$existingProfilePayload,
                        'sso' => $ssoLookupProfile,
                        'sso_checked_at' => now()->toISOString(),
                    ],
                    'contact_number' => $ssoMappedProfile['contact_number'] ?: $existingUser?->contact_number,
                    'mobile_no' => $ssoMappedProfile['mobile_no'] ?: $existingUser?->mobile_no,
                    'avatar' => $ssoMappedProfile['avatar'] ?: $existingUser?->avatar,
                    'password' => $existingUser?->password ?: Hash::make(Str::random(40)),
                    'email_verified_at' => now(),
                    'is_active' => true,
                ]
            );

            $guest = Role::firstOrCreate(['name' => 'guest', 'guard_name' => 'web']);

            if ($wasCreated) {
                // Non-registered SSO users always start as guest + pending access.
                $user->syncRoles([$guest]);
                $user->forceFill([
                    'access_status' => 'pending',
                    'requested_role' => 'guest',
                    'access_requested_at' => now(),
                ])->save();
            } elseif ($user->roles()->count() === 0) {
                $user->assignRole($guest);
            }

            Auth::login($user, true);
            $request->session()->regenerate();

            session([
                'idp_access_token' => $accessToken,
                'idp_refresh_token' => $tokens['refresh_token'] ?? null,
                'idp_expires_in' => $tokens['expires_in'] ?? null,
            ]);

            $user->refresh();
            $this->audit->log('auth.sso_login', $user, [], ['method' => 'caraga_connect'], $user->id);

            if ($wasCreated) {
                $this->audit->log('user.registered_sso', $user, [], [
                    'email' => $user->email,
                    'username' => $user->username,
                    'access_status' => $user->access_status,
                ], $user->id);
            }

            if ($this->shouldBypassMfaAuthentication()) {
                $request->session()->forget('mfa_required');
                Log::info('MFA bypass enabled - Redirecting to dashboard', [
                    'user_id' => $user->id,
                    'mfa_enabled' => $user->mfa_enabled,
                ]);

                return $this->redirectToPostAuth($request);
            }

            if ($user->mfa_enabled) {
                session(['mfa_required' => true]);
                Log::info('MFA enabled - Redirecting to verify page', [
                    'user_id' => $user->id,
                    'mfa_enabled' => $user->mfa_enabled,
                ]);

                return redirect()->route('mfa.verify');
            }

            session(['mfa_required' => true]);
            Log::info('MFA not enabled - Redirecting to setup page', [
                'user_id' => $user->id,
                'mfa_enabled' => $user->mfa_enabled,
            ]);

            return redirect()->route('mfa.setup');
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first() ?: 'Caraga Connect sign-in failed.';

            return $this->ssoFailure($message);
        } catch (Throwable $exception) {
            Log::warning('Caraga Connect callback failed.', [
                'exception' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return $this->ssoFailure('Caraga Connect sign-in could not be completed. Please try again.');
        }
    }

    /**
     * Kept for older handoff URLs; new flow logs in during callback.
     */
    public function complete(Request $request): RedirectResponse
    {
        return $this->redirectToPostAuth($request);
    }

    private function redirectToPostAuth(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user && $user->access_status !== 'approved') {
            return redirect()->route('access.request')
                ->with('success', 'Caraga Connect sign-in successful. Please request your user-level access from the Super Admin.');
        }

        return redirect()->intended(route('dashboard'));
    }

    private function shouldBypassMfaAuthentication(): bool
    {
        return (bool) config('services.cc_idp.bypass_mfa', true);
    }

    private function ensureConfigured(): void
    {
        foreach (['client_id', 'client_secret', 'authorize_url', 'token_url', 'userinfo_url', 'redirect_uri'] as $key) {
            if (blank(config('services.cc_idp.'.$key))) {
                throw new \RuntimeException('Missing Caraga Connect configuration: '.$key);
            }
        }
    }

    private function ssoFailure(string $message): RedirectResponse
    {
        return redirect()->away(rtrim(config('app.url'), '/').'/login?sso_error='.urlencode($message));
    }
}
