<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('barangay_populations', function (Blueprint $table): void {
            if (! Schema::hasColumn('barangay_populations', 'estimated_families')) {
                $table->unsignedInteger('estimated_families')->nullable()->after('population');
            }
        });
    }

    public function down(): void
    {
        Schema::table('barangay_populations', function (Blueprint $table): void {
            if (Schema::hasColumn('barangay_populations', 'estimated_families')) {
                $table->dropColumn('estimated_families');
            }
        });
    }
};
