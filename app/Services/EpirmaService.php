<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Caraga Connect e-PIRMA microservice client.
 *
 * @see https://caraga-connect-dev.dswd.gov.ph/docs/epirma-php
 */
class EpirmaService
{
    private string $baseUrl;

    private string $clientSecret;

    private bool $verifySsl;

    public function __construct(?array $config = null)
    {
        $config ??= $this->loadConfig();

        $this->baseUrl = $config['base_url'];
        $this->clientSecret = $config['client_secret'];
        $this->verifySsl = $config['verify_ssl'];
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->clientSecret !== '';
    }

    /**
     * Register an integration client.
     * Docs: POST /epirma/register-client { "name": "My Application" }
     *
     * @return array{success: bool, message?: string, secret?: string, expires_at?: string, status?: int, body?: string, data?: array}
     */
    public function registerClient(string $name, ?string $baseUrl = null): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['success' => false, 'message' => 'Client name is required.'];
        }

        $hosts = array_values(array_unique(array_filter([
            rtrim((string) ($baseUrl ?: $this->baseUrl), '/'),
            rtrim((string) config('services.epirma.register_base_url', ''), '/'),
            'https://caraga-connect-dev.dswd.gov.ph',
            'https://caraga-connect.dswd.gov.ph',
        ])));

        $paths = array_values(array_unique(array_filter([
            trim((string) config('services.epirma.register_path', '')),
            // Staff swagger path used on Caraga Connect Dev.
            '/api/v1/staff/epirma/register-client',
            '/epirma/register-client',
            '/api/epirma/register-client',
        ])));

        $last = ['success' => false, 'message' => 'Client registration failed.'];

        foreach ($hosts as $host) {
            foreach ($paths as $path) {
                $path = '/'.ltrim($path, '/');
                try {
                    $response = $this->http()
                        ->acceptJson()
                        ->asJson()
                        ->post($host.$path, ['name' => $name]);

                    $json = $response->json();
                    $payload = is_array($json) ? $json : [];
                    $nested = data_get($payload, 'data.data', data_get($payload, 'data', $payload));
                    $secret = trim((string) data_get($nested, 'secret', data_get($payload, 'secret', '')));

                    if ($response->successful() && $secret !== '') {
                        return [
                            'success' => true,
                            'secret' => $secret,
                            'expires_at' => (string) data_get($nested, 'expires_at', ''),
                            'data' => is_array($nested) ? $nested : $payload,
                            'status' => $response->status(),
                            'host' => $host,
                        ];
                    }

                    $last = [
                        'success' => false,
                        'message' => trim((string) ($payload['message'] ?? $payload['error'] ?? 'Client registration failed.')),
                        'status' => $response->status(),
                        'body' => $response->body(),
                        'host' => $host.$path,
                    ];
                } catch (Throwable $e) {
                    $last = [
                        'success' => false,
                        'message' => $e->getMessage(),
                        'host' => $host.$path,
                    ];
                }
            }
        }

        return $last;
    }

    /**
     * Build authorization for document access (microservice API).
     */
    public function buildAuthorize(string $idNumber): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'message' => 'E-Pirma is not configured.'];
        }

        $idNumber = trim($idNumber);
        if ($idNumber === '') {
            return [
                'success' => false,
                'message' => 'Your employee ID number is not set or not registered in e-PIRMA.',
            ];
        }

        try {
            $response = $this->http()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->acceptJson()
                ->asJson()
                ->post($this->baseUrl.'/api/microservice/build-authorize', [
                    'id_number' => $idNumber,
                    'secret' => $this->clientSecret,
                ]);

            if ($response->successful()) {
                $data = $response->json();

                return is_array($data) ? $data : ['success' => false];
            }

            Log::error('Build authorize failed', [
                'status' => $response->status(),
                'response' => $response->body(),
                'id_number' => $idNumber,
            ]);

            $data = $response->json();
            $apiMessage = is_array($data)
                ? trim((string) ($data['message'] ?? $data['error'] ?? ''))
                : '';

            return [
                'success' => false,
                'message' => $apiMessage !== ''
                    ? $apiMessage
                    : 'Failed to initialize E-Pirma document creation. You must have an ePIRMA Account to use this feature.',
                'status' => $response->status(),
                'details' => is_array($data) ? $data : null,
            ];
        } catch (Throwable $e) {
            Log::error('Build authorize exception', [
                'error' => $e->getMessage(),
                'base_url' => $this->baseUrl,
                'id_number' => $idNumber,
            ]);
        }

        return [
            'success' => false,
            'message' => 'Could not reach e-PIRMA at '.$this->baseUrl.'. Confirm EPIRMA_BASE_URL and network access, then try again.',
        ];
    }

    /**
     * Generate document routing URL for E-Pirma (microservice).
     */
    public function generateDocumentRoutingUrl(
        string $appName,
        string $documentUuid,
        string $token,
        string $callbackUrl,
        ?string $documentUrl = null
    ): string {
        $queryData = [
            'app_name' => $appName,
            'external_document_uuid' => $documentUuid,
            'redirect_url' => $this->toPublicUrl($callbackUrl),
            'secret' => $this->clientSecret,
            'token' => $token,
        ];

        if ($documentUrl !== null && $documentUrl !== '') {
            $queryData['document_url'] = $this->toPublicUrl($documentUrl);
        }

        return $this->baseUrl.'/microservice/document-routing?'.http_build_query($queryData);
    }

    /**
     * Get authorized document URL (for viewing with token).
     */
    public function getAuthorizedDocumentUrl(string $documentPath, string $token): string
    {
        $separator = str_contains($documentPath, '?') ? '&' : '?';

        return $documentPath.$separator.http_build_query(['token' => $token]);
    }

    /**
     * Ensure URLs handed to the HTTPS e-PIRMA UI are not mixed-content http:// links.
     */
    public function toPublicUrl(string $url): string
    {
        if ($url === '') {
            return $url;
        }

        $forceHttps = (bool) config('services.epirma.force_https_urls', false);
        $root = rtrim((string) config('services.epirma.public_app_url', ''), '/');

        if ($root !== '') {
            $path = parse_url($url, PHP_URL_PATH) ?? '';
            $query = parse_url($url, PHP_URL_QUERY);
            $url = $root.$path.($query ? '?'.$query : '');
        }

        if ($forceHttps && str_starts_with($url, 'http://')) {
            return 'https://'.substr($url, strlen('http://'));
        }

        return $url;
    }

    /**
     * Fetch latest document base path and signer statuses by external UUID.
     *
     * Cancel / soft-delete note (observed on caraga-epirma-dev):
     * UI "Out for Signature" cancel often leaves this endpoint returning HTTP 200 with
     * pending signers and no top-level status. When the document is actually gone,
     * e-PIRMA returns HTTP 404 `{"error":"Document not found."}` — that is the clear
     * remote cancel/gone signal DROMIS can trust. Unsigned docs still 404 on
     * /api/signed-document, so that endpoint alone is NOT a cancel signal.
     *
     * @return array{success: bool, data?: array<string, mixed>, message?: string, not_found?: bool, status?: int}
     */
    public function fetchLatestDocumentBasePath(string $documentUuid): array
    {
        $documentUuid = trim($documentUuid);
        if ($documentUuid === '') {
            return ['success' => false, 'message' => 'Document UUID is required.'];
        }

        try {
            $response = $this->http()
                ->withHeaders(['Accept' => 'application/json'])
                ->get($this->baseUrl.'/api/latest-document-base-path/'.rawurlencode($documentUuid));

            if ($response->successful()) {
                $data = $response->json();
                if (is_array($data)) {
                    return ['success' => true, 'data' => $data, 'status' => $response->status()];
                }

                return ['success' => false, 'message' => 'Invalid response payload from E-Pirma.', 'status' => $response->status()];
            }

            $notFound = $this->responseIndicatesDocumentNotFound($response->status(), $response->body());

            Log::error('Fetch latest document base path failed', [
                'status' => $response->status(),
                'response' => $response->body(),
                'document_uuid' => $documentUuid,
                'not_found' => $notFound,
            ]);

            return [
                'success' => false,
                'message' => $notFound ? 'Document not found.' : 'Failed to fetch latest document base path.',
                'not_found' => $notFound,
                'status' => $response->status(),
            ];
        } catch (Throwable $e) {
            Log::error('Fetch latest document base path exception', [
                'error' => $e->getMessage(),
                'document_uuid' => $documentUuid,
            ]);
        }

        return ['success' => false, 'message' => 'Failed to fetch latest document base path.', 'not_found' => false];
    }

    /**
     * @return array{success: bool, data?: array<string, mixed>, message?: string, not_found?: bool, status?: int}
     */
    public function fetchSignedDocumentStatus(string $documentUuid): array
    {
        $documentUuid = trim($documentUuid);
        if ($documentUuid === '') {
            return ['success' => false, 'message' => 'Document UUID is required.'];
        }

        try {
            $response = $this->http()
                ->acceptJson()
                ->get($this->baseUrl.'/api/signed-document/'.rawurlencode($documentUuid));

            if ($response->successful()) {
                $data = $response->json();
                if (is_array($data)) {
                    return ['success' => true, 'data' => $data, 'status' => $response->status()];
                }

                return ['success' => false, 'message' => 'Invalid response payload from E-Pirma.', 'status' => $response->status()];
            }

            $notFound = $this->responseIndicatesDocumentNotFound($response->status(), $response->body());

            Log::warning('E-Pirma signed document status failed.', [
                'status' => $response->status(),
                'body' => $response->body(),
                'document_uuid' => $documentUuid,
                'not_found' => $notFound,
            ]);

            return [
                'success' => false,
                'message' => $notFound ? 'Signed document not found.' : 'Failed to fetch signed document status.',
                'not_found' => $notFound,
                'status' => $response->status(),
            ];
        } catch (Throwable $e) {
            Log::warning('E-Pirma signed document status exception.', [
                'message' => $e->getMessage(),
                'document_uuid' => $documentUuid,
            ]);
        }

        return ['success' => false, 'message' => 'Failed to fetch signed document status.', 'not_found' => false];
    }

    /**
     * List forwarded e-PIRMA documents for a Connect staff username.
     * GET {connect}/api/v1/staff/epirma/forwarded-documents?username=…
     *
     * Auth: EPIRMA_CONNECT_BEARER, else MYPORTAL_ACCESS_TOKEN / staff login credentials.
     *
     * @return array{success: bool, documents?: array<int, array<string, mixed>>, message?: string, status?: int}
     */
    public function fetchForwardedDocuments(string $username): array
    {
        $username = trim($username);
        if ($username === '') {
            return ['success' => false, 'message' => 'Connect username is required for forwarded-documents.'];
        }

        $bearer = $this->resolveConnectStaffBearer(false);
        if ($bearer === null || $bearer === '') {
            return [
                'success' => false,
                'message' => 'Connect staff bearer is not configured. Set EPIRMA_CONNECT_BEARER or MYPORTAL_ACCESS_TOKEN / MYPORTAL_USERNAME+PASSWORD '
                    .'(login host must match EPIRMA_CONNECT_BASE_URL — e.g. connect-dev credentials for e-PIRMA-dev).',
                'auth_missing' => true,
            ];
        }

        $base = rtrim((string) (
            config('services.epirma.connect_base_url')
            ?: config('services.epirma.register_base_url')
            ?: 'https://caraga-connect-dev.dswd.gov.ph'
        ), '/');
        $path = '/'.ltrim((string) config(
            'services.epirma.forwarded_documents_path',
            '/api/v1/staff/epirma/forwarded-documents'
        ), '/');

        $attempt = function (string $token) use ($base, $path, $username): Response {
            $headers = [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$token,
            ];
            $csrf = trim((string) config('services.epirma.connect_csrf_token', ''));
            if ($csrf !== '') {
                $headers['X-CSRF-TOKEN'] = $csrf;
            }

            return $this->httpForConnectHost($base)
                ->withHeaders($headers)
                ->get($base.$path, ['username' => $username]);
        };

        try {
            $response = $attempt($bearer);

            // Stale MYPORTAL_ACCESS_TOKEN / cross-host token → refresh via staff login once.
            if ($response->status() === 401) {
                Log::info('Connect forwarded-documents returned 401; retrying with fresh staff login.', [
                    'username' => $username,
                    'connect_base' => $base,
                ]);
                $fresh = $this->resolveConnectStaffBearer(true);
                if (filled($fresh) && $fresh !== $bearer) {
                    $response = $attempt($fresh);
                }
            }

            if ($response->successful()) {
                $json = $response->json();
                $documents = data_get($json, 'data.documents', data_get($json, 'documents', []));
                if (! is_array($documents)) {
                    $documents = [];
                }

                return [
                    'success' => true,
                    'documents' => array_values(array_filter($documents, 'is_array')),
                    'status' => $response->status(),
                ];
            }

            Log::warning('Connect forwarded-documents lookup failed.', [
                'status' => $response->status(),
                'username' => $username,
                'body' => Str::limit($response->body(), 500),
            ]);

            $authFailed = in_array($response->status(), [401, 403], true);

            return [
                'success' => false,
                'message' => $authFailed
                    ? 'Connect forwarded-documents auth failed (HTTP '.$response->status().'). '
                        .'MYPORTAL_LOGIN_URL / EPIRMA_CONNECT_BEARER must be for the same host as EPIRMA_CONNECT_BASE_URL '
                        .'('.$base.'). Prod Connect tokens cannot call connect-dev.'
                    : 'Failed to fetch forwarded documents from Caraga Connect.',
                'status' => $response->status(),
                'auth_missing' => $authFailed,
            ];
        } catch (Throwable $e) {
            Log::warning('Connect forwarded-documents exception.', [
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
        }

        return ['success' => false, 'message' => 'Failed to fetch forwarded documents from Caraga Connect.'];
    }

    /**
     * Prefer EPIRMA_CONNECT_BEARER, then MyPortal staff token.
     * When $forceRefresh is true, skip static tokens and obtain a fresh staff login token.
     */
    public function resolveConnectStaffBearer(bool $forceRefresh = false): ?string
    {
        if (! $forceRefresh) {
            $configured = trim((string) config('services.epirma.connect_bearer', ''));
            if ($configured !== '') {
                return $configured;
            }
        }

        try {
            $token = app(SSOAuthService::class)->resolveStaffAccessToken($forceRefresh);

            return filled($token) ? $token : null;
        } catch (Throwable $e) {
            Log::info('Connect staff bearer unavailable for forwarded-documents.', [
                'error' => $e->getMessage(),
                'force_refresh' => $forceRefresh,
            ]);
        }

        return null;
    }

    private function httpForConnectHost(string $baseUrl): PendingRequest
    {
        $request = Http::timeout(30)
            ->when(! $this->verifySsl, fn ($http) => $http->withoutVerifying());

        $host = strtolower((string) (parse_url($baseUrl, PHP_URL_HOST) ?: ''));
        $resolve = $this->curlResolveEntries($host);
        if ($resolve !== []) {
            $request = $request->withOptions([
                'curl' => [CURLOPT_RESOLVE => $resolve],
            ]);
        }

        return $request;
    }

    private function responseIndicatesDocumentNotFound(int $status, string $body): bool
    {
        if (! in_array($status, [404, 410], true)) {
            return false;
        }

        // Ignore framework "route could not be found" HTML/JSON — only document-not-found payloads.
        if (str_contains(strtolower($body), 'could not be found')) {
            return false;
        }

        $json = json_decode($body, true);
        $message = strtolower(trim((string) (
            (is_array($json) ? ($json['error'] ?? $json['message'] ?? '') : '')
        )));
        $haystack = $message !== '' ? $message : strtolower($body);

        // Require an explicit document-not-found signal. Generic gateway/HTML "Not Found"
        // bodies must not accumulate toward a local cancel (false positives right after handoff).
        return str_contains($haystack, 'document not found')
            || str_contains($haystack, 'signed document not found');
    }

    private function loadConfig(): array
    {
        $baseUrl = trim((string) config('services.epirma.base_url', ''));

        if ($baseUrl !== '' && ! preg_match('#^https?://#i', $baseUrl)) {
            $baseUrl = 'http://'.$baseUrl;
        }

        $verifySsl = (bool) config('services.epirma.verify_ssl', ! app()->environment('local'));

        // Docs use withOptions(['verify' => false]) for local/dev integrations.
        if (app()->environment('local') && (bool) config('services.epirma.allow_insecure_ssl', true)) {
            $verifySsl = false;
        }

        $secret = trim((string) config('services.epirma.client_secret', ''));
        $secret = trim($secret, "\"'");

        return [
            'base_url' => rtrim($baseUrl, '/'),
            'client_secret' => $secret,
            'verify_ssl' => $verifySsl,
        ];
    }

    private function http(): PendingRequest
    {
        $request = Http::timeout(30)
            ->when(! $this->verifySsl, fn ($http) => $http->withoutVerifying());

        $host = strtolower((string) (parse_url($this->baseUrl, PHP_URL_HOST) ?: ''));
        $resolve = $this->curlResolveEntries($host);
        if ($resolve !== []) {
            $request = $request->withOptions([
                'curl' => [CURLOPT_RESOLVE => $resolve],
            ]);
        }

        return $request;
    }

    /**
     * @return list<string>
     */
    private function curlResolveEntries(string $host): array
    {
        if ($host === '') {
            return [];
        }

        $map = config('services.epirma.resolve_map', []);
        if (! is_array($map)) {
            return [];
        }

        $ip = $map[$host] ?? null;
        if (! is_string($ip) || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return [];
        }

        return [
            $host.':443:'.$ip,
            $host.':80:'.$ip,
        ];
    }
}
