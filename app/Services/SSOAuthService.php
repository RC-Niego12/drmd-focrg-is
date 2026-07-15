<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SSOAuthService
{
    public function buildAuthorizeRedirect(): string
    {
        $codeVerifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        $state = bin2hex(random_bytes(16));

        // The registered Caraga Connect callback may use a different host from
        // the application (for example 127.0.0.1 during local development).
        // Keep the PKCE transaction server-side so it is not tied to a
        // host-specific browser session cookie.
        Cache::put($this->transactionKey($state), $codeVerifier, now()->addMinutes(10));

        return config('services.cc_idp.authorize_url').'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.cc_idp.client_id'),
            'redirect_uri' => config('services.cc_idp.redirect_uri'),
            'scope' => config('services.cc_idp.scope'),
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    public function exchangeCodeForTokens(string $code, string $state): array
    {
        $codeVerifier = Cache::pull($this->transactionKey($state));

        if (! $codeVerifier) {
            throw new RuntimeException('Missing SSO PKCE verifier.');
        }

        $response = Http::asForm()
            ->when(! config('services.cc_idp.verify_ssl'), fn ($http) => $http->withoutVerifying())
            ->post(config('services.cc_idp.token_url'), [
                'grant_type' => 'authorization_code',
                'client_id' => config('services.cc_idp.client_id'),
                'client_secret' => config('services.cc_idp.client_secret'),
                'redirect_uri' => config('services.cc_idp.redirect_uri'),
                'code' => $code,
                'code_verifier' => $codeVerifier,
            ]);

        if (! $response->ok()) {
            throw new RuntimeException('SSO token exchange failed: '.$response->body());
        }

        return $response->json();
    }

    public function fetchUserInfo(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->when(! config('services.cc_idp.verify_ssl'), fn ($http) => $http->withoutVerifying())
            ->get(config('services.cc_idp.userinfo_url'));

        if (! $response->ok()) {
            throw new RuntimeException('SSO userinfo request failed: '.$response->body());
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException('SSO userinfo response was invalid.');
        }

        return $data;
    }

    public function hasPendingTransaction(string $state): bool
    {
        return Cache::has($this->transactionKey($state));
    }

    private function transactionKey(string $state): string
    {
        return 'caraga-connect:oauth:'.hash('sha256', $state);
    }

    public function logout(string $accessToken, bool $logoutAll = false): array
    {
        $url = $logoutAll ? config('services.cc_idp.logout_all_url') : config('services.cc_idp.logout_url');

        if (! $url) {
            return ['success' => false, 'error' => 'SSO logout URL is not configured.'];
        }

        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->when(! config('services.cc_idp.verify_ssl'), fn ($http) => $http->withoutVerifying())
            ->post($url);

        return [
            'success' => $response->ok(),
            'status' => $response->status(),
            'data' => $response->json(),
            'error' => $response->ok() ? null : $response->body(),
        ];
    }
}
