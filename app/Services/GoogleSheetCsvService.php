<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleSheetCsvService
{
    public function fetchWorksheet(string $sheetId, string $worksheet): string
    {
        $worksheet = trim($worksheet);
        if ($worksheet === '') {
            throw new RuntimeException('Google Sheets worksheet name is not configured.');
        }

        return $this->fetchUrls([
            "https://docs.google.com/spreadsheets/d/{$sheetId}/gviz/tq?tqx=out:csv&sheet=".rawurlencode($worksheet),
            "https://docs.google.com/spreadsheets/d/{$sheetId}/export?format=csv&sheet=".rawurlencode($worksheet),
        ]);
    }

    public function fetch(string $sheetId, string $gid): string
    {
        return $this->fetchUrls([
            "https://docs.google.com/spreadsheets/d/{$sheetId}/gviz/tq?tqx=out:csv&gid={$gid}",
            "https://docs.google.com/spreadsheets/d/{$sheetId}/export?format=csv&gid={$gid}",
        ]);
    }

    private function fetchUrls(array $urls): string
    {
        $lastError = null;

        foreach ($urls as $url) {
            try {
                $response = $this->request($url);
                if ($response->successful() && trim($response->body()) !== '') {
                    return $response->body();
                }
                $lastError = "HTTP {$response->status()}";
            } catch (ConnectionException $exception) {
                $lastError = $exception->getMessage();
            }
        }

        throw new RuntimeException(
            'Google Sheets could not be reached after several attempts. No synchronization data was changed.',
            previous: isset($exception) ? $exception : null,
        );
    }

    private function request(string $url): Response
    {
        $request = Http::connectTimeout(max(5, (int) config('services.google_sheets.connect_timeout', 15)))
            ->timeout(max(30, (int) config('services.google_sheets.request_timeout', 60)))
            ->retry(
                max(1, (int) config('services.google_sheets.retry_attempts', 2)),
                fn (int $attempt): int => min(3000, 500 * (2 ** ($attempt - 1))),
                throw: false,
            );

        if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
            $request = $request->withOptions([
                'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
            ]);
        }

        return $request->get($url);
    }
}
