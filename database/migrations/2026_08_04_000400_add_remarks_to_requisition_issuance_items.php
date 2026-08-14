<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisition_issuance_items', function (Blueprint $table): void {
            $table->text('remarks')->nullable()->after('allocation_status');
        });
    }

    public function down(): void
    {
        Schema::table('requisition_issuance_items', function (Blueprint $table): void {
            $table->dropColumn('remarks');
        });
    }
};
