<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisition_issuance_slips', function (Blueprint $table): void {
            $table->json('tracking_data')->nullable()->after('items');
        });
    }

    public function down(): void
    {
        Schema::table('requisition_issuance_slips', fn (Blueprint $table) => $table->dropColumn('tracking_data'));
    }
};
