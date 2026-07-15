<?php

namespace App\Services;

use App\Models\RequestParty;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class RequestPartySheetService
{
    public const CSV_URL = 'https://docs.google.com/spreadsheets/d/1SBk2PJyS44KS4ftAsjvV9q31GdIEodanrHZOWMScsEw/export?format=csv&gid=2135034250';

    public function sync(): int
    {
        $body = Http::retry(2, 500)->timeout(30)->get(self::CSV_URL)->throw()->body();
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $body);
        rewind($stream);
        $header = array_map(fn ($value) => trim((string) $value, "\xEF\xBB\xBF \t\n\r\0\x0B"), fgetcsv($stream, null, ',', '"', '') ?: []);
        $columns = array_flip($header);
        $required = ['Office/Agency Details', 'Requesting Party', 'LGU Level', 'Office Head'];

        foreach ($required as $name) {
            if (! array_key_exists($name, $columns)) {
                throw new \RuntimeException("WIT sheet column missing: {$name}");
            }
        }

        $records = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $get = fn (string $name) => trim((string) ($row[$columns[$name]] ?? '')) ?: null;
            $party = $get('Requesting Party');
            if (! $party) {
                continue;
            }
            $details = $get('Office/Agency Details');
            $key = mb_strtolower(($details ?? '').'|'.$party);
            $records[$key] = [
                'directory_key' => hash('sha256', $key),
                'office_agency_details' => $details,
                'requesting_party' => $party,
                'lgu_level' => $get('LGU Level'),
                'office_head' => $get('Office Head'),
                'source' => 'WIT Data Entry',
                'is_active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ];
        }
        fclose($stream);

        DB::transaction(function () use ($records): void {
            RequestParty::upsert(array_values($records), ['directory_key'], ['office_agency_details', 'requesting_party', 'lgu_level', 'office_head', 'source', 'is_active', 'updated_at']);
        });

        return count($records);
    }
}
