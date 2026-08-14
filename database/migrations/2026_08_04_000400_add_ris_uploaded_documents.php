<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisition_issuance_slips', function (Blueprint $table): void {
            $table->string('rds_path')->nullable();
            $table->string('rds_name')->nullable();
            $table->string('csmr_path')->nullable();
            $table->string('csmr_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('requisition_issuance_slips', fn (Blueprint $table) => $table->dropColumn(['rds_path', 'rds_name', 'csmr_path', 'csmr_name']));
    }
};
