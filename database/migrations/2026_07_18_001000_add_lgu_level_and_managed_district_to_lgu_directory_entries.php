<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lgu_directory_entries', function (Blueprint $table): void {
            if (! Schema::hasColumn('lgu_directory_entries', 'lgu_level')) {
                $table->string('lgu_level', 20)->nullable()->after('psgc_code')->index();
            }

            if (! Schema::hasColumn('lgu_directory_entries', 'managed_district_code')) {
                $table->string('managed_district_code', 20)->nullable()->after('lgu_level')->index();
            }

            if (! Schema::hasColumn('lgu_directory_entries', 'managed_district_name')) {
                $table->string('managed_district_name')->nullable()->after('managed_district_code');
            }
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('lgu_directory_entries', function (Blueprint $table): void {
            foreach (['lgu_level', 'managed_district_code', 'managed_district_name'] as $column) {
                if (Schema::hasColumn('lgu_directory_entries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function backfill(): void
    {
        DB::table('lgu_directory_entries')
            ->orderBy('id')
            ->get(['id', 'psgc_code'])
            ->each(function ($entry): void {
                $lce = DB::table('lgu_directory_officials')
                    ->where('lgu_directory_entry_id', $entry->id)
                    ->where('role', 'lce')
                    ->first(['position_designation', 'override_position_designation']);

                $position = strtolower((string) ($lce?->override_position_designation ?: $lce?->position_designation));
                $address = filled($entry->psgc_code)
                    ? DB::table('psgc_addresses')->where('code', $entry->psgc_code)->first(['code', 'level', 'type', 'district', 'district_code'])
                    : null;

                $district = null;
                if (filled($address?->district_code)) {
                    $district = DB::table('psgc_addresses')
                        ->where('code', $address->district_code)
                        ->where('level', 'district')
                        ->first(['code', 'name', 'short_name']);
                }

                DB::table('lgu_directory_entries')
                    ->where('id', $entry->id)
                    ->update([
                        'lgu_level' => $this->levelFromPositionOrAddress($position, $address),
                        'managed_district_code' => $district?->code ?: $address?->district_code,
                        'managed_district_name' => $district?->short_name ?: $district?->name ?: $address?->district,
                        'updated_at' => now(),
                    ]);
            });
    }

    private function levelFromPositionOrAddress(string $position, ?object $address): ?string
    {
        if (str_contains($position, 'municipal mayor')) {
            return 'MLGU';
        }

        if (str_contains($position, 'city mayor')) {
            return 'CLGU';
        }

        if (str_contains($position, 'governor')) {
            return 'PLGU';
        }

        $level = strtolower((string) ($address?->level ?? ''));
        $type = strtolower((string) ($address?->type ?? ''));

        return match (true) {
            $level === 'province' => 'PLGU',
            str_contains($type, 'city') || $level === 'city' || $level === 'city_municipality' => 'CLGU',
            $level === 'municipality' => 'MLGU',
            default => null,
        };
    }
};
