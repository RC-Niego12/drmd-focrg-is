<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisition_issuance_slips', function (Blueprint $table): void {
            $table->string('ris_dr_path')->nullable();
            $table->string('ris_dr_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('requisition_issuance_slips', fn (Blueprint $table) => $table->dropColumn(['ris_dr_path', 'ris_dr_name']));
    }
};
