<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class StandbyFundSheetSyncService
{
    public function fetchAmount(string $sheetUrl, string $cell = 'L2'): float
    {
        [$sheetId, $gid] = $this->extractSheetParts($sheetUrl);
        $csvUrl = "https://docs.google.com/spreadsheets/d/{$sheetId}/export?format=csv&gid={$gid}";

        try {
            $response = Http::timeout(60)->get($csvUrl);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Could not connect to the standby fund Google Sheet. Please check the internet connection or DNS settings, then try syncing again.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException("Standby fund sheet export failed with HTTP {$response->status()}.");
        }

        $coordinates = $this->cellCoordinates($cell);
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $response->body());
        rewind($handle);

        $rowNumber = 0;
        while (($columns = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if ($rowNumber === $coordinates['row']) {
                fclose($handle);

                return $this->number($columns[$coordinates['column']] ?? 0);
            }
        }

        fclose($handle);

        throw new RuntimeException("Cell {$cell} was not found in the standby fund Google Sheet.");
    }

    private function cellCoordinates(string $cell): array
    {
        if (! preg_match('/^([A-Z]+)([0-9]+)$/i', trim($cell), $matches)) {
            throw new RuntimeException('Invalid standby fund cell reference.');
        }

        $letters = strtoupper($matches[1]);
        $column = 0;

        foreach (str_split($letters) as $letter) {
            $column = ($column * 26) + (ord($letter) - 64);
        }

        return [
            'column' => $column - 1,
            'row' => (int) $matches[2],
        ];
    }

    private function number(mixed $value): float
    {
        return (float) preg_replace('/[^0-9.\-]/', '', (string) $value);
    }

    private function extractSheetParts(string $url): array
    {
        preg_match('/\/d\/([^\/]+)/', $url, $sheetMatches);
        preg_match('/gid=(\d+)/', $url, $gidMatches);

        if (empty($sheetMatches[1])) {
            throw new RuntimeException('Invalid Google Sheet URL.');
        }

        return [$sheetMatches[1], $gidMatches[1] ?? '0'];
    }
}
