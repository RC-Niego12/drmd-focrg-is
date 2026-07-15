<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('psgc_addresses', function (Blueprint $table): void {
            if (! Schema::hasColumn('psgc_addresses', 'district_code')) {
                $table->string('district_code', 20)->nullable()->after('district')->index();
            }
        });

        Schema::table('barangay_demographics', function (Blueprint $table): void {
            if (! Schema::hasColumn('barangay_demographics', 'year')) {
                $table->unsignedSmallInteger('year')->nullable()->after('source')->index();
            }
        });

        $districts = DB::table('psgc_addresses')
            ->where('level', 'district')
            ->where('is_active', true)
            ->get(['code', 'parent_code', 'name', 'short_name'])
            ->groupBy('parent_code');

        DB::table('psgc_addresses')
            ->where('level', 'city_municipality')
            ->whereNotNull('district')
            ->orderBy('id')
            ->chunkById(200, function ($cities) use ($districts): void {
                foreach ($cities as $city) {
                    $district = ($districts[$city->parent_code] ?? collect())
                        ->first(fn ($row): bool => $this->same($row->name, $city->district) || $this->same($row->short_name, $city->district));

                    if ($district) {
                        DB::table('psgc_addresses')
                            ->where('id', $city->id)
                            ->update(['district_code' => $district->code]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('barangay_demographics', function (Blueprint $table): void {
            if (Schema::hasColumn('barangay_demographics', 'year')) {
                $table->dropColumn('year');
            }
        });

        Schema::table('psgc_addresses', function (Blueprint $table): void {
            if (Schema::hasColumn('psgc_addresses', 'district_code')) {
                $table->dropColumn('district_code');
            }
        });
    }

    private function same(?string $left, ?string $right): bool
    {
        $normalize = fn (?string $value): string => preg_replace('/[^a-z0-9]+/', '', strtolower((string) $value)) ?? '';

        return $normalize($left) !== '' && $normalize($left) === $normalize($right);
    }
};
