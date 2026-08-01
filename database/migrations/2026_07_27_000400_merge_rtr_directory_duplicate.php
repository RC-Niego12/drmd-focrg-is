<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('lgu_directory_entries')) {
            return;
        }

        $canonical = DB::table('lgu_directory_entries')
            ->where('source_sheet', 'ADN')
            ->where(function ($query): void {
                $query->where('psgc_code', '1600212000')
                    ->orWhereRaw('UPPER(lgu_name) = ?', ['REMEDIOS T. ROMUALDEZ']);
            })
            ->orderByRaw("CASE WHEN psgc_code = '1600212000' THEN 0 ELSE 1 END")
            ->first();

        if (! $canonical) {
            return;
        }

        $duplicates = DB::table('lgu_directory_entries')
            ->where('source_sheet', 'ADN')
            ->where('id', '!=', $canonical->id)
            ->whereRaw("UPPER(REPLACE(REPLACE(lgu_name, '.', ''), ' ', '')) = 'RTR'")
            ->get();

        foreach ($duplicates as $duplicate) {
            $this->mergeEntry($canonical, $duplicate);
        }

        if (Schema::hasTable('users')) {
            $updates = ['lgu_name' => 'REMEDIOS T. ROMUALDEZ'];
            if (Schema::hasColumn('users', 'lgu_psgc_code')) {
                $updates['lgu_psgc_code'] = '1600212000';
            }
            DB::table('users')->whereRaw("UPPER(REPLACE(REPLACE(lgu_name, '.', ''), ' ', '')) = 'RTR'")->update($updates);
        }
    }

    private function mergeEntry(object $canonical, object $duplicate): void
    {
        $entryUpdates = [];
        foreach ([
            'override_lgu_name',
            'override_congressional_district',
            'override_office_address',
            'lgu_logo_path',
            'lce_photo_path',
            'lswd_photo_path',
            'ldrrmo_photo_path',
        ] as $column) {
            if (Schema::hasColumn('lgu_directory_entries', $column)
                && blank($canonical->{$column} ?? null)
                && filled($duplicate->{$column} ?? null)) {
                $entryUpdates[$column] = $duplicate->{$column};
            }
        }
        if ($entryUpdates !== []) {
            DB::table('lgu_directory_entries')->where('id', $canonical->id)->update($entryUpdates);
        }

        if (Schema::hasTable('request_parties')) {
            DB::table('request_parties')
                ->where('lgu_directory_entry_id', $duplicate->id)
                ->update(['lgu_directory_entry_id' => $canonical->id]);
        }

        $this->mergeUniqueChildren(
            'lgu_directory_officials',
            $canonical->id,
            $duplicate->id,
            ['role'],
            ['override_name', 'override_position_designation'],
        );
        $this->mergeUniqueChildren(
            'lgu_directory_contacts',
            $canonical->id,
            $duplicate->id,
            ['owner_role', 'contact_type'],
            ['override_value'],
        );

        if (Schema::hasTable('lgu_directory_ldrrmo_officers')) {
            $hasCanonicalLdrrmo = DB::table('lgu_directory_ldrrmo_officers')
                ->where('lgu_directory_entry_id', $canonical->id)
                ->exists();
            if (! $hasCanonicalLdrrmo) {
                DB::table('lgu_directory_ldrrmo_officers')
                    ->where('lgu_directory_entry_id', $duplicate->id)
                    ->update(['lgu_directory_entry_id' => $canonical->id]);
            }
        }

        if (Schema::hasTable('lgu_directory_lswdo_alternates')) {
            $hasCanonicalAlternates = DB::table('lgu_directory_lswdo_alternates')
                ->where('lgu_directory_entry_id', $canonical->id)
                ->exists();
            DB::table('lgu_directory_lswdo_alternates')
                ->where('lgu_directory_entry_id', $duplicate->id)
                ->when($hasCanonicalAlternates, fn ($query) => $query->where('is_locally_updated', true))
                ->update(['lgu_directory_entry_id' => $canonical->id]);
        }

        DB::table('lgu_directory_entries')->where('id', $duplicate->id)->delete();
    }

    private function mergeUniqueChildren(
        string $table,
        int $canonicalId,
        int $duplicateId,
        array $identityColumns,
        array $localColumns,
    ): void {
        if (! Schema::hasTable($table)) {
            return;
        }

        DB::table($table)
            ->where('lgu_directory_entry_id', $duplicateId)
            ->orderBy('id')
            ->each(function (object $child) use ($table, $canonicalId, $identityColumns, $localColumns): void {
                $query = DB::table($table)->where('lgu_directory_entry_id', $canonicalId);
                foreach ($identityColumns as $column) {
                    $query->where($column, $child->{$column});
                }
                $existing = $query->first();

                if (! $existing) {
                    DB::table($table)->where('id', $child->id)->update([
                        'lgu_directory_entry_id' => $canonicalId,
                    ]);
                    return;
                }

                $updates = [];
                foreach ($localColumns as $column) {
                    if (blank($existing->{$column} ?? null) && filled($child->{$column} ?? null)) {
                        $updates[$column] = $child->{$column};
                    }
                }
                if ($updates !== []) {
                    DB::table($table)->where('id', $existing->id)->update($updates);
                }
            });
    }

    public function down(): void
    {
        // The duplicate is intentionally not recreated.
    }
};
