<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SSOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SSOController extends Controller
{
    public function __construct(
        private readonly SSOAuthService $sso,
        private readonly AuditLogger $audit,
    ) {}

    public function login(): RedirectResponse
    {
        try {
            $this->ensureConfigured();

            return redirect()->away($this->sso->buildAuthorizeRedirect());
        } catch (Throwable $exception) {
            Log::warning('Caraga Connect authorization could not start.', ['exception' => $exception]);

            return redirect()->route('login')->with('error', 'Caraga Connect is temporarily unavailable. Please try again or use your local account.');
        }
    }

    public function callback(Request $request): RedirectResponse
    {
        try {
            if ($error = $request->query('error')) {
                return $this->ssoFailure('Caraga Connect did not authorize the sign-in: '.$error);
            }

            $state = (string) $request->query('state', '');
            $code = (string) $request->query('code', '');

            if (! $state || ! $code || ! $this->sso->hasPendingTransaction($state)) {
                return $this->ssoFailure('The Caraga Connect sign-in expired or could not be verified. Please start again.');
            }

            $tokens = $this->sso->exchangeCodeForTokens($code, $state);
            $accessToken = $tokens['access_token'] ?? null;

            if (! $accessToken) {
                return $this->ssoFailure('Caraga Connect did not return a valid access token.');
            }

            $profile = $this->sso->fetchUserInfo($accessToken);
            $user = $this->resolveUser($profile);

            $handoff = Str::random(64);
            Cache::put('caraga-connect:handoff:'.hash('sha256', $handoff), [
                'user_id' => $user->id,
                'access_token' => $accessToken,
                'refresh_token' => $tokens['refresh_token'] ?? null,
                'expires_in' => $tokens['expires_in'] ?? null,
            ], now()->addMinutes(2));

            return redirect()->away(rtrim(config('app.url'), '/').'/sso/complete?token='.urlencode($handoff));
        } catch (Throwable $exception) {
            Log::warning('Caraga Connect callback failed.', ['exception' => $exception]);

            return $this->ssoFailure('Caraga Connect sign-in could not be completed. Please try again.');
        }
    }

    public function complete(Request $request): RedirectResponse
    {
        $token = (string) $request->query('token', '');
        $payload = $token ? Cache::pull('caraga-connect:handoff:'.hash('sha256', $token)) : null;

        if (! is_array($payload) || empty($payload['user_id'])) {
            return $this->ssoFailure('The Caraga Connect sign-in handoff expired. Please start again.');
        }

        $user = User::find($payload['user_id']);

        if (! $user) {
            return $this->ssoFailure('The Caraga Connect user account could not be found.');
        }

        Auth::login($user, true);
        $request->session()->regenerate();
        $request->session()->put([
            'idp_access_token' => $payload['access_token'] ?? null,
            'idp_refresh_token' => $payload['refresh_token'] ?? null,
            'idp_expires_in' => $payload['expires_in'] ?? null,
        ]);
        $this->audit->log('auth.sso_login', $user, [], ['method' => 'caraga_connect'], $user->id);

        if ($user->access_status !== 'approved') {
            return redirect()->route('access.request')
                ->with('success', 'Caraga Connect sign-in successful. Please request your user-level access from the Super Admin.');
        }

        return redirect()->intended(route('dashboard'));
    }

    private function resolveUser(array $profile): User
    {
        $sub = $profile['sub'] ?? null;

        if (! $sub) {
            throw new \RuntimeException('SSO provider did not return a subject identifier.');
        }

        $email = $profile['email'] ?? null;
        $username = $profile['preferred_username'] ?? null;
        $idNumber = $profile['id_number'] ?? null;
        $contactNumber = $this->normalizeContactNumber($profile['contact_number'] ?? null);

        $user = User::where('sso_sub', $sub)->first()
            ?? ($email ? User::where('email', $email)->first() : null)
            ?? new User(['sso_sub' => $sub]);
        $wasCreated = ! $user->exists;

        $user->fill([
            'sso_sub' => $user->sso_sub ?: $sub,
            'name' => $profile['name'] ?? $username ?? 'Caraga Connect User',
            'email' => $email ?: ($username ?: Str::slug($sub)).'@caraga-connect.local',
            'username' => $username,
            'id_number' => $idNumber,
            'contact_number' => $contactNumber,
            'mobile_no' => $contactNumber,
            'office' => $user->office ?: config('services.cc_idp.default_office'),
            'is_active' => true,
            'mfa_enabled' => false,
            'mfa_verified' => false,
            'email_verified_at' => now(),
        ]);

        if ($wasCreated) {
            $user->forceFill([
                'password' => Hash::make(Str::random(48)),
                'access_status' => 'pending',
                'access_requested_at' => now(),
            ]);
        }

        $user->save();

        if ($wasCreated) {
            $this->audit->log('user.registered_sso', $user, [], [
                'email' => $user->email,
                'username' => $user->username,
                'access_status' => $user->access_status,
            ], $user->id);
        }

        return $user;
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
        // This redirect may cross from the registered loopback callback to the
        // Herd hostname, so a session flash would not be visible there.
        return redirect()->away(rtrim(config('app.url'), '/').'/login?sso_error='.urlencode($message));
    }

    private function normalizeContactNumber(?string $contactNumber): ?string
    {
        if (! $contactNumber) {
            return null;
        }

        if (str_starts_with($contactNumber, '+63')) {
            return $contactNumber;
        }

        if (str_starts_with($contactNumber, '09')) {
            return preg_replace('/^09/', '+639', $contactNumber);
        }

        return $contactNumber;
    }
}
