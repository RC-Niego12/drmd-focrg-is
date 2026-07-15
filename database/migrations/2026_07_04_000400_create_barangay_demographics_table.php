<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('barangay_demographics', function (Blueprint $table) {
            $table->string('psgc_code', 20)->primary();
            $table->string('region')->nullable();
            $table->string('province')->nullable();
            $table->string('city_municipality')->nullable();
            $table->string('barangay')->nullable();
            $table->unsignedInteger('population_2024')->nullable();
            $table->unsignedInteger('poor_families')->nullable();
            $table->unsignedInteger('poor_individuals')->nullable();
            $table->date('date_updated')->nullable();
            $table->string('source')->nullable();
            $table->timestamps();

            $table->index(['region', 'province', 'city_municipality']);
        });

        if (
            Schema::hasTable('warehouses')
            && Schema::hasColumn('warehouses', 'barangay_code')
            && Schema::hasColumn('warehouses', 'population')
            && Schema::hasColumn('warehouses', 'poor_families')
            && Schema::hasColumn('warehouses', 'poor_individuals')
        ) {
            $now = now();

            DB::table('warehouses')
                ->select([
                    'barangay_code',
                    'office',
                    'province',
                    'municipality',
                    'barangay_name',
                    'population',
                    'poor_families',
                    'poor_individuals',
                ])
                ->whereNotNull('barangay_code')
                ->where('barangay_code', '<>', '')
                ->orderBy('id')
                ->chunk(200, function ($warehouses) use ($now): void {
                    foreach ($warehouses as $warehouse) {
                        DB::table('barangay_demographics')->updateOrInsert(
                            ['psgc_code' => $warehouse->barangay_code],
                            [
                                'region' => $warehouse->office,
                                'province' => $warehouse->province,
                                'city_municipality' => $warehouse->municipality,
                                'barangay' => $warehouse->barangay_name,
                                'population_2024' => $warehouse->population,
                                'poor_families' => $warehouse->poor_families,
                                'poor_individuals' => $warehouse->poor_individuals,
                                'date_updated' => $now->toDateString(),
                                'source' => 'Migrated from WIT Managed Warehouses legacy demographic columns',
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]
                        );
                    }
                });
        }

        Schema::table('warehouses', function (Blueprint $table) {
            if (Schema::hasColumn('warehouses', 'barangay_code')) {
                $table->index('barangay_code');
            }
        });

        Schema::table('warehouses', function (Blueprint $table) {
            foreach (['population', 'poor_families', 'poor_individuals'] as $column) {
                if (Schema::hasColumn('warehouses', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            if (! Schema::hasColumn('warehouses', 'population')) {
                $table->unsignedInteger('population')->nullable();
            }

            if (! Schema::hasColumn('warehouses', 'poor_families')) {
                $table->unsignedInteger('poor_families')->nullable();
            }

            if (! Schema::hasColumn('warehouses', 'poor_individuals')) {
                $table->unsignedInteger('poor_individuals')->nullable();
            }
        });

        if (Schema::hasTable('barangay_demographics')) {
            DB::table('barangay_demographics')
                ->orderBy('psgc_code')
                ->chunk(200, function ($rows): void {
                    foreach ($rows as $row) {
                        DB::table('warehouses')
                            ->where('barangay_code', $row->psgc_code)
                            ->update([
                                'population' => $row->population_2024,
                                'poor_families' => $row->poor_families,
                                'poor_individuals' => $row->poor_individuals,
                            ]);
                    }
                });
        }

        Schema::dropIfExists('barangay_demographics');
    }
};
