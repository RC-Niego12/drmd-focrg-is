<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('dispatch_plans', function (Blueprint $table): void {
            $table->json('local_handover_details')->nullable()->after('fulfillment_type');
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_plans', fn (Blueprint $table) => $table->dropColumn('local_handover_details'));
    }
};
