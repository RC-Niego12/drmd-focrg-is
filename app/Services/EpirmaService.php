<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    public function buildAuthorize(string $idNumber): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'message' => 'E-Pirma is not configured.'];
        }

        $idNumber = trim($idNumber);
        if ($idNumber === '') {
            return [
                'success' => false,
                'message' => 'Your profile has no employee ID number. Update your DROMIS profile id_number, then try again.',
            ];
        }

        try {
            $response = $this->http()
                ->acceptJson()
                ->asJson()
                ->post($this->baseUrl.'/api/microservice/build-authorize', [
                    'id_number' => $idNumber,
                    'secret' => $this->clientSecret,
                ]);

            $data = $response->json();
            if (is_array($data)) {
                if ($response->successful()) {
                    return $data;
                }

                $apiMessage = trim((string) ($data['message'] ?? $data['error'] ?? ''));
                if ($apiMessage !== '') {
                    Log::warning('E-Pirma build authorize failed.', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                        'id_number' => $idNumber,
                    ]);

                    return [
                        'success' => false,
                        'message' => $apiMessage,
                        'status' => $response->status(),
                        'details' => $data,
                    ];
                }
            }

            Log::warning('E-Pirma build authorize failed.', [
                'status' => $response->status(),
                'body' => $response->body(),
                'id_number' => $idNumber,
            ]);

            return [
                'success' => false,
                'message' => 'e-PIRMA rejected authorization for employee ID '.$idNumber.' (HTTP '.$response->status().').',
                'status' => $response->status(),
            ];
        } catch (Throwable $e) {
            Log::warning('E-Pirma build authorize exception.', [
                'message' => $e->getMessage(),
                'base_url' => $this->baseUrl,
                'id_number' => $idNumber,
            ]);

            return [
                'success' => false,
                'message' => 'Could not reach e-PIRMA at '.$this->baseUrl.'. Start the e-PIRMA service, then try signing again.',
            ];
        }
    }

    public function generateDocumentRoutingUrl(
        string $appName,
        string $documentUuid,
        string $token,
        string $callbackUrl,
        ?string $documentUrl = null
    ): string {
        return $this->buildDocumentRoutingUrl(
            '/microservice/document-routing',
            $appName,
            $documentUuid,
            $token,
            $callbackUrl,
            $documentUrl
        );
    }

    public function generateDocumentRoutingUrlforSigning(
        string $appName,
        string $documentUuid,
        string $token,
        string $callbackUrl,
        ?string $documentUrl = null
    ): string {
        return $this->buildDocumentRoutingUrl(
            '/microservice/documents',
            $appName,
            $documentUuid,
            $token,
            $callbackUrl,
            $documentUrl
        );
    }

    /**
     * Ensure URLs handed to the HTTPS e-PIRMA UI are not mixed-content http:// links.
     */
    public function toPublicUrl(string $url): string
    {
        if ($url === '') {
            return $url;
        }

        $forceHttps = (bool) config('services.epirma.force_https_urls', true);
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

    public function fetchLatestDocumentBasePath(string $documentUuid): array
    {
        return $this->fetchDocumentPayload(
            '/api/latest-document-base-path/',
            $documentUuid,
            'E-Pirma latest document base path',
            'Failed to fetch latest document base path.'
        );
    }

    public function fetchSignedDocumentStatus(string $documentUuid): array
    {
        return $this->fetchDocumentPayload(
            '/api/signed-document/',
            $documentUuid,
            'E-Pirma signed document status',
            'Failed to fetch signed document status.'
        );
    }

    private function buildDocumentRoutingUrl(
        string $path,
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

        $path = '/'.ltrim($path, '/');
        $separator = str_contains($path, '?') ? '&' : '?';

        return $this->baseUrl.rtrim($path, '?&').$separator.http_build_query($queryData);
    }

    private function fetchDocumentPayload(
        string $pathPrefix,
        string $documentUuid,
        string $logLabel,
        string $failureMessage
    ): array {
        $documentUuid = trim($documentUuid);

        if ($documentUuid === '') {
            return [
                'success' => false,
                'message' => 'Document UUID is required.',
            ];
        }

        try {
            $response = $this->http()
                ->acceptJson()
                ->get($this->baseUrl.$pathPrefix.rawurlencode($documentUuid));

            if ($response->successful()) {
                $data = $response->json();

                if (is_array($data)) {
                    return [
                        'success' => true,
                        'data' => $data,
                    ];
                }

                return [
                    'success' => false,
                    'message' => 'Invalid response payload from E-Pirma.',
                ];
            }

            Log::warning("{$logLabel} failed.", [
                'status' => $response->status(),
                'body' => $response->body(),
                'document_uuid' => $documentUuid,
            ]);
        } catch (Throwable $e) {
            Log::warning("{$logLabel} exception.", [
                'message' => $e->getMessage(),
                'document_uuid' => $documentUuid,
            ]);
        }

        return [
            'success' => false,
            'message' => $failureMessage,
        ];
    }

    private function loadConfig(): array
    {
        $baseUrl = trim((string) config('services.epirma.base_url', ''));

        if ($baseUrl !== '' && ! preg_match('#^https?://#i', $baseUrl)) {
            $baseUrl = 'http://'.$baseUrl;
        }

        $verifySsl = (bool) config('services.epirma.verify_ssl', ! app()->environment('local'));

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

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout(30)
            ->when(! $this->verifySsl, fn ($http) => $http->withoutVerifying());
    }
}
