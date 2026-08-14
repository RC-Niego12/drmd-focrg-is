<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table): void {
            $table->string('reconciliation_status')->nullable()->index()->after('external_status');
            $table->timestamp('reconciled_at')->nullable()->after('reconciliation_status');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table): void {
            $table->dropColumn(['reconciliation_status', 'reconciled_at']);
        });
    }
};
