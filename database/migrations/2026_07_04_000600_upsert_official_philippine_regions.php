<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('psgc_addresses')) {
            return;
        }

        $now = now();
        $regions = [
            ['short_name' => 'REGION I', 'name' => 'Ilocos Region', 'code' => '0100000000'],
            ['short_name' => 'REGION II', 'name' => 'Cagayan Valley', 'code' => '0200000000'],
            ['short_name' => 'REGION III', 'name' => 'Central Luzon', 'code' => '0300000000'],
            ['short_name' => 'CALABARZON', 'name' => 'CALABARZON', 'code' => '0400000000'],
            ['short_name' => 'REGION V', 'name' => 'Bicol Region', 'code' => '0500000000'],
            ['short_name' => 'REGION VI', 'name' => 'Western Visayas', 'code' => '0600000000'],
            ['short_name' => 'REGION VII', 'name' => 'Central Visayas', 'code' => '0700000000'],
            ['short_name' => 'REGION VIII', 'name' => 'Eastern Visayas', 'code' => '0800000000'],
            ['short_name' => 'REGION IX', 'name' => 'Zamboanga Peninsula', 'code' => '0900000000'],
            ['short_name' => 'REGION X', 'name' => 'Northern Mindanao', 'code' => '1000000000'],
            ['short_name' => 'REGION XI', 'name' => 'Davao Region', 'code' => '1100000000'],
            ['short_name' => 'REGION XII', 'name' => 'SOCCSKSARGEN', 'code' => '1200000000'],
            ['short_name' => 'NCR', 'name' => 'National Capital Region', 'code' => '1300000000'],
            ['short_name' => 'CAR', 'name' => 'Cordillera Administrative Region', 'code' => '1400000000'],
            ['short_name' => 'CARAGA', 'name' => 'Caraga', 'code' => '1600000000'],
            ['short_name' => 'MIMAROPA', 'name' => 'MIMAROPA Region', 'code' => '1700000000'],
            ['short_name' => 'NIR', 'name' => 'Negros Island Region', 'code' => '1800000000'],
            ['short_name' => 'BARMM', 'name' => 'Bangsamoro Autonomous Region in Muslim Mindanao', 'code' => '1900000000'],
        ];

        foreach ($regions as $region) {
            $legacyCode = substr($region['code'], 0, 9);

            DB::table('psgc_addresses')
                ->where('code', $legacyCode)
                ->where('level', 'region')
                ->update(['code' => $region['code']]);

            DB::table('psgc_addresses')
                ->where('parent_code', $legacyCode)
                ->update(['parent_code' => $region['code']]);

            DB::table('system_settings')
                ->where('key', 'default_region_code')
                ->where('value', $legacyCode)
                ->update(['value' => $region['code'], 'updated_at' => $now]);

            DB::table('psgc_addresses')->updateOrInsert(
                ['code' => $region['code']],
                [
                    'parent_code' => null,
                    'level' => 'region',
                    'name' => $region['name'],
                    'short_name' => $region['short_name'],
                    'type' => 'Administrative Region',
                    'district' => null,
                    'district_code' => null,
                    'zip_code' => null,
                    'raw_payload' => json_encode([
                        'basis' => 'Official Philippine administrative regions including NIR under Republic Act No. 12000',
                        'region_code' => $region['short_name'],
                    ]),
                    'source' => 'manual',
                    'source_version' => 'Official 18 Philippine administrative regions',
                    'sync_batch' => 'official-regions-18',
                    'synced_at' => $now,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        DB::table('psgc_addresses')
            ->where('level', 'region')
            ->whereNotIn('code', collect($regions)->pluck('code')->all())
            ->update([
                'is_active' => false,
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        DB::table('psgc_addresses')
            ->where('level', 'region')
            ->where('sync_batch', 'official-regions-18')
            ->update([
                'sync_batch' => null,
                'source_version' => null,
            ]);
    }
};
