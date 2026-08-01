<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $lgus = DB::table('lgu_directory_entries')
            ->where('is_active', true)
            ->get(['id', 'lgu_name', 'override_lgu_name', 'lgu_level'])
            ->keyBy(fn ($row): string => $this->normalizedPlace($row->override_lgu_name ?: $row->lgu_name));

        DB::table('request_parties')
            ->orderBy('id')
            ->get(['id', 'office_agency_details', 'requesting_party'])
            ->each(function ($party) use ($lgus): void {
                $label = trim(explode(',', (string) ($party->office_agency_details ?: preg_replace('/^[A-Z]+\s*-\s*/', '', $party->requesting_party)))[0]);
                if (strtoupper($label) === 'RTR') {
                    $label = 'Remedios T. Romualdez';
                }

                $match = $lgus->get($this->normalizedPlace($label));
                if (! $match) {
                    return;
                }

                DB::table('request_parties')
                    ->where('id', $party->id)
                    ->update([
                        'lgu_directory_entry_id' => $match->id,
                        'lgu_level' => $match->lgu_level ?: null,
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        // This is a data normalization migration. It intentionally does not
        // undo request-party links because older records may already rely on
        // them for routing and assessment letter context.
    }

    private function normalizedPlace(?string $name): string
    {
        $name = preg_replace('/\([^)]*\)/u', '', (string) $name);
        $name = strtoupper(trim($name));
        $name = preg_replace('/^PROVINCE\s+OF\s+/', '', $name);
        $name = preg_replace('/^CITY\s+OF\s+/', '', $name);
        $name = preg_replace('/\s+CITY$/', '', $name);

        return Str::of($name)->replaceMatches('/[^A-Z0-9]+/', '')->toString();
    }
};
