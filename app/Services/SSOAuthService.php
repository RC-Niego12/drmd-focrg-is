<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    public function authorizationProblem(string $authorizeUrl): ?string
    {
        $response = Http::when(! config('services.cc_idp.verify_ssl'), fn ($http) => $http->withoutVerifying())
            ->withOptions(['allow_redirects' => false])
            ->get($authorizeUrl);

        $body = $response->body();

        if (
            str_contains($body, 'SSO Access Denied')
            || str_contains($body, 'System Not Registered')
            || str_contains($body, 'Invalid Redirect URI')
        ) {
            return 'Caraga Connect rejected this SSO client or callback URL. Please ask Caraga Connect admin/RICTMS to register this exact redirect URI: '.config('services.cc_idp.redirect_uri');
        }

        return null;
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
                throw new RuntimeException('Fetching userinfo failed: '.$response->body());
            }

            $data = $response->json();

            if (! is_array($data)) {
                throw new RuntimeException('Invalid userinfo response format');
            }

            return $data;
        } catch (\Throwable $exception) {
            Log::error('UserInfo API exception:', [
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Fetch the authenticated employee profile from MyPortal through the
     * documented Caraga Connect staff portal API:
     * POST /api/v1/staff/login -> token, then GET /api/v1/staff/portal/me/details.
     */
    public function fetchMyPortalProfile(): array
    {
        $token = $this->myPortalToken();
        $url = (string) config('services.cc_idp.myportal_me_details_url');

        if (blank($url)) {
            throw new RuntimeException('MyPortal details URL is not configured.');
        }

        $response = Http::acceptJson()
            ->withToken($token)
            ->connectTimeout(3)
            ->timeout(8)
            ->when(! config('services.cc_idp.verify_ssl'), fn ($http) => $http->withoutVerifying())
            ->get($url);

        Log::info('MyPortal details API response:', [
            'status' => $response->status(),
            'url' => $url,
            'has_data' => is_array(data_get($response->json(), 'data')),
            'has_image_path' => filled(data_get($response->json(), 'data.image_path')),
        ]);

        if ($response->status() === 401) {
            throw new RuntimeException('MyPortal rejected the login token. Please refresh the portal credentials.');
        }

        if (! $response->ok()) {
            throw new RuntimeException('MyPortal profile lookup failed: '.$this->myPortalFailureMessage($response->status(), $response->json(), $response->body()));
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('MyPortal returned an invalid profile response.');
        }

        return $payload;
    }

    public function fetchMyPortalProfileForIdentity(array $identity): array
    {
        $idNumber = trim((string) ($identity['id_number'] ?? ''));

        if (filled($idNumber)) {
            try {
                $profile = $this->fetchMyPortalProfileByIdNumber($idNumber);

                if ($this->profileMatchesIdentity($profile, $identity)) {
                    return $profile;
                }

                Log::warning('MyPortal details-by-ID returned a profile that does not match SSO identity.', [
                    'id_number' => $idNumber,
                    'returned_id_number' => data_get($profile, 'data.id_number') ?: data_get($profile, 'id_number'),
                ]);
            } catch (\Throwable $exception) {
                Log::warning('MyPortal details-by-ID lookup failed; trying search fallback.', [
                    'id_number' => $idNumber,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $queries = collect([
            $identity['id_number'] ?? null,
            $identity['email'] ?? null,
            $identity['username'] ?? null,
            $identity['name'] ?? null,
        ])->filter(fn ($value): bool => filled($value))
            ->map(fn ($value): string => trim((string) $value))
            ->unique()
            ->values();

        foreach ($queries as $query) {
            $profile = $this->searchMyPortalProfile($query, $identity);

            if ($profile !== null) {
                return $profile;
            }
        }

        try {
            $profile = $this->fetchMyPortalProfile();

            if ($this->profileMatchesIdentity($profile, $identity) || ! $this->hasReliableIdentity($identity)) {
                return $profile;
            }

            Log::warning('MyPortal generic profile fallback returned a profile that does not match SSO identity.', [
                'sso_username' => $identity['username'] ?? null,
                'sso_email' => $identity['email'] ?? null,
                'sso_id_number' => $identity['id_number'] ?? null,
                'myportal_username' => data_get($profile, 'data.username') ?: data_get($profile, 'username'),
                'myportal_email' => data_get($profile, 'data.email') ?: data_get($profile, 'email'),
                'myportal_id_number' => data_get($profile, 'data.id_number') ?: data_get($profile, 'id_number'),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('MyPortal generic profile fallback failed.', [
                'message' => $exception->getMessage(),
            ]);
        }

        throw new RuntimeException('MyPortal did not return a matching employee profile for the signed-in SSO user.');
    }

    private function hasReliableIdentity(array $identity): bool
    {
        $idNumber = trim((string) ($identity['id_number'] ?? ''));
        $username = trim((string) ($identity['username'] ?? ''));
        $email = Str::lower(trim((string) ($identity['email'] ?? '')));

        return $idNumber !== ''
            || ($username !== '' && ! str_contains($username, 'example'))
            || ($email !== '' && ! str_ends_with($email, '@example.test') && ! str_ends_with($email, '@test'));
    }

    private function fetchMyPortalProfileByIdNumber(string $idNumber): array
    {
        $token = $this->myPortalToken();
        $template = (string) config('services.cc_idp.myportal_employee_details_url');

        if (blank($template)) {
            $template = rtrim((string) config('services.cc_idp.myportal_me_details_url'), '/').'/'.$idNumber;
        }

        $url = str_replace('{id_number}', rawurlencode($idNumber), $template);
        $response = $this->myPortalJsonRequest($token)->get($url);

        Log::info('MyPortal employee details API response:', [
            'status' => $response->status(),
            'url' => $url,
            'has_data' => is_array(data_get($response->json(), 'data')),
            'has_image_path' => filled(data_get($response->json(), 'data.image_path')),
        ]);

        if (! $response->ok()) {
            throw new RuntimeException('MyPortal employee details lookup failed: '.$this->myPortalFailureMessage($response->status(), $response->json(), $response->body()));
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('MyPortal returned an invalid employee details response.');
        }

        return $payload;
    }

    private function searchMyPortalProfile(string $query, array $identity): ?array
    {
        $token = $this->myPortalToken();
        $url = (string) config('services.cc_idp.myportal_employee_search_url');

        if (blank($url)) {
            return null;
        }

        $response = $this->myPortalJsonRequest($token)->get($url, ['q' => $query]);

        Log::info('MyPortal employee search API response:', [
            'status' => $response->status(),
            'url' => $url,
            'query' => $query,
            'has_data' => is_array(data_get($response->json(), 'data')),
        ]);

        if (! $response->ok()) {
            return null;
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return null;
        }

        $records = $this->extractEmployeeRecords($payload);

        foreach ($records as $record) {
            $wrapped = ['status' => data_get($payload, 'status', 'success'), 'data' => $record];

            if ($this->profileMatchesIdentity($wrapped, $identity)) {
                return $wrapped;
            }
        }

        return null;
    }

    private function myPortalJsonRequest(string $token): \Illuminate\Http\Client\PendingRequest
    {
        return Http::acceptJson()
            ->withToken($token)
            ->connectTimeout(3)
            ->timeout(6)
            ->when(! config('services.cc_idp.verify_ssl'), fn ($http) => $http->withoutVerifying());
    }

    private function extractEmployeeRecords(array $payload): array
    {
        $data = data_get($payload, 'data');

        if (is_array($data) && array_is_list($data)) {
            return $data;
        }

        foreach (['data.data', 'employees', 'results', 'records'] as $key) {
            $records = data_get($payload, $key);

            if (is_array($records) && array_is_list($records)) {
                return $records;
            }
        }

        return is_array($data) ? [$data] : [];
    }

    public function profileMatchesIdentity(array $profile, array $identity): bool
    {
        $checks = [
            'username' => ['data.username', 'username'],
            'email' => ['data.email', 'data.official_email', 'email', 'official_email'],
            'id_number' => ['data.id_number', 'id_number'],
        ];

        foreach ($checks as $field => $keys) {
            $expected = Str::lower(trim((string) ($identity[$field] ?? '')));

            if ($expected === '') {
                continue;
            }

            foreach ($keys as $key) {
                $actual = Str::lower(trim((string) data_get($profile, $key)));

                if ($actual !== '' && $actual === $expected) {
                    return true;
                }
            }
        }

        return false;
    }

    public function mapProfileToUserFields(array $profile): array
    {
        $name = $this->profileValue($profile, ['name', 'full_name', 'fullname', 'display_name', 'displayname', 'data.fullname'])
            ?: $this->composedNameFromProfile($profile);

        return [
            'sso_sub' => $this->profileValue($profile, ['sub', 'id', 'sso_sub', 'user_id', 'data.sub', 'data.id']),
            'username' => $this->profileValue($profile, ['preferred_username', 'username', 'user_name', 'data.preferred_username', 'data.username']),
            'name' => $name,
            'email' => $this->profileValue($profile, ['email', 'mail', 'email_address', 'data.email', 'data.mail']),
            'id_number' => $this->profileValue($profile, ['id_number', 'employee_id', 'employee_no', 'employee_number', 'id_no', 'data.id_number', 'data.employee_id']),
            'office' => $this->profileValue($profile, ['office', 'office_section', 'section', 'division', 'department', 'data.office', 'data.section', 'data.division']),
            'position' => $this->profileValue($profile, ['position', 'position_title', 'job_title', 'title', 'data.position', 'data.job_title']),
            'designation' => $this->profileValue($profile, ['designation', 'designation_title', 'functional_designation', 'data.designation']),
            'area_of_assignment' => $this->profileValue($profile, ['area_of_assignment', 'area_assignment', 'place_of_assignment', 'station', 'duty_station', 'data.area_of_assignment']),
            'employment_status' => $this->profileValue($profile, ['data.empstatus', 'data.employment_status', 'data.employee_status', 'data.status', 'empstatus', 'employment_status', 'employee_status', 'employment_type', 'status']),
            'contact_number' => $this->profileValue($profile, ['contact_number', 'contact', 'phone_number', 'telephone', 'contact_no', 'data.contact_number', 'data.contact']),
            'mobile_no' => $this->profileValue($profile, ['mobile_no', 'mobile_number', 'mobile', 'cellphone', 'data.mobile_no', 'data.mobile']),
            'avatar' => $this->normalizeAvatarUrl($this->profileValue($profile, ['data.saved_image_path', 'data.image_path', 'image', 'image_path', 'saved_image_path', 'avatar', 'photo', 'photo_url', 'profile_photo_url', 'picture', 'data.image', 'data.avatar', 'data.photo'])),
        ];
    }

    public function normalizeAvatarUrl(?string $avatar): ?string
    {
        if (blank($avatar)) {
            return null;
        }

        $avatar = trim((string) $avatar);

        if (str_starts_with($avatar, 'data:image/') || filter_var($avatar, FILTER_VALIDATE_URL)) {
            return $avatar;
        }

        return 'https://caraga-connect-dev.dswd.gov.ph/'.ltrim($avatar, '/');
    }

    public function isTrustedIdentityUrl(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! $host) {
            return false;
        }

        return collect(explode(',', (string) config('services.cc_idp.myportal_trusted_media_hosts')))
            ->map(fn (string $trustedHost): string => trim($trustedHost))
            ->filter()
            ->contains(fn (string $trustedHost): bool => strcasecmp($host, $trustedHost) === 0);
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

    private function myPortalToken(): string
    {
        $configuredToken = trim((string) config('services.cc_idp.myportal_access_token'));

        if (filled($configuredToken)) {
            return $configuredToken;
        }

        $username = (string) config('services.cc_idp.myportal_username');
        $password = (string) config('services.cc_idp.myportal_password');
        $url = (string) config('services.cc_idp.myportal_login_url');

        if (blank($username) || blank($password) || blank($url)) {
            throw new RuntimeException('MyPortal credentials are not configured.');
        }

        $cacheKey = 'myportal:staff-token:'.sha1($url.'|'.$username);

        return Cache::remember($cacheKey, max(60, (int) config('services.cc_idp.myportal_token_cache_seconds')), function () use ($username, $password, $url): string {
            $response = Http::acceptJson()
                ->connectTimeout(3)
                ->timeout(8)
                ->when(! config('services.cc_idp.verify_ssl'), fn ($http) => $http->withoutVerifying())
                ->asMultipart()
                ->post($url, [
                    ['name' => 'username', 'contents' => $username],
                    ['name' => 'password', 'contents' => $password],
                ]);

            Log::info('MyPortal login API response:', [
                'status' => $response->status(),
                'url' => $url,
                'has_token' => filled(data_get($response->json(), 'token')),
            ]);

            if ($response->status() === 401) {
                throw new RuntimeException('MyPortal login failed. The configured portal username/passkey was rejected.');
            }

            if (! $response->ok()) {
                throw new RuntimeException('MyPortal login failed: HTTP '.$response->status());
            }

            $token = data_get($response->json(), 'token');

            if (! is_string($token) || blank($token)) {
                throw new RuntimeException('MyPortal login did not return a token.');
            }

            return $token;
        });
    }

    private function myPortalFailureMessage(int $status, mixed $json, string $body): string
    {
        $message = is_array($json)
            ? (data_get($json, 'message') ?: data_get($json, 'description'))
            : null;

        $message = is_string($message) && filled($message)
            ? $message
            : (preg_replace('/\s+/', ' ', $body) ?: '');

        if (
            $status >= 500
            && (
                str_contains($message, 'caraga-portal-dev.dswd.gov.ph')
                || str_contains($message, 'api/employee/list/search')
            )
        ) {
            return 'Caraga Connect accepted the MyPortal token, but its MyPortal employee lookup bridge is unreachable. Please ask the Caraga Connect/MyPortal administrator to restore the employee lookup service or point the bridge to the reachable MyPortal host.';
        }

        return filled($message) ? Str::limit($message, 240) : 'HTTP '.$status;
    }

    private function profileValue(array $profile, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = data_get($profile, $key);

            if (filled($value) && is_scalar($value)) {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function composedNameFromProfile(array $profile): ?string
    {
        $parts = [
            $this->profileValue($profile, ['first_name', 'first name', 'firstname', 'given_name', 'data.first_name']),
            $this->profileValue($profile, ['middle_name', 'middle name', 'middlename', 'data.middle_name']),
            $this->profileValue($profile, ['last_name', 'last name', 'lastname', 'family_name', 'data.last_name']),
        ];

        $name = collect($parts)->filter()->implode(' ');

        return filled($name) ? $this->normalizePersonName($name) : null;
    }

    private function normalizePersonName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?: $name);

        if ($name === mb_strtoupper($name)) {
            return Str::of($name)
                ->lower()
                ->title()
                ->replaceMatches('/\b([A-Z])\b(?!\.)/', '$1.')
                ->toString();
        }

        return $name;
    }
}
