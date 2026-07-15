<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SSOAuthService
{
    private const PKCE_TTL_SECONDS = 900;

    public function buildAuthorizeRedirect(): string
    {
        $codeVerifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $codeChallenge = rtrim(strtr(
            base64_encode(hash('sha256', $codeVerifier, true)),
            '+/',
            '-_'
        ), '=');
        $state = bin2hex(random_bytes(16));

        // Cache-backed PKCE keyed by state — survives OAuth round-trips even when
        // the browser session is stale or overwritten by a concurrent SSO attempt.
        Cache::put($this->pkceCacheKey($state), $codeVerifier, self::PKCE_TTL_SECONDS);

        $query = [
            'response_type' => 'code',
            'client_id' => config('services.cc_idp.client_id'),
            'redirect_uri' => config('services.cc_idp.redirect_uri'),
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        $scope = trim((string) config('services.cc_idp.scope'));
        if ($scope !== '') {
            $query['scope'] = $scope;
        }

        return config('services.cc_idp.authorize_url').'?'.http_build_query($query);
    }

    public function consumePkceVerifier(string $state): string
    {
        $codeVerifier = Cache::pull($this->pkceCacheKey($state));

        if (! is_string($codeVerifier) || $codeVerifier === '') {
            throw new RuntimeException('Invalid or expired SSO state.');
        }

        return $codeVerifier;
    }

    public function exchangeCodeForTokens(string $code, string $codeVerifier): array
    {
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
            throw new RuntimeException('Token exchange failed: '.$response->body());
        }

        return $response->json();
    }

    public function fetchUserInfo(string $accessToken): array
    {
        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->when(! config('services.cc_idp.verify_ssl'), fn ($http) => $http->withoutVerifying())
                ->get(config('services.cc_idp.userinfo_url'));

            Log::info('UserInfo API Response:', [
                'status' => $response->status(),
                'body' => $response->body(),
                'url' => config('services.cc_idp.userinfo_url'),
            ]);

            if (! $response->ok()) {
                Log::error('UserInfo API failed:', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                throw new RuntimeException('Fetching userinfo failed: '.$response->body());
            }

            $data = $response->json();

            if (! is_array($data)) {
                Log::error('UserInfo API returned invalid data:', ['data' => $data]);

                throw new RuntimeException('Invalid userinfo response format');
            }

            return $data;
        } catch (\Exception $e) {
            Log::error('UserInfo API exception:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
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

    private function pkceCacheKey(string $state): string
    {
        return 'sso:pkce:'.$state;
    }
}
