<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisition_issuance_slips', function (Blueprint $table): void {
            $table->string('reservation_status', 20)->default('active')->after('status')->index();
        });

        DB::table('requisition_issuance_slips')->where('status', 'cancelled')->update(['reservation_status' => 'released']);
    }

    public function down(): void
    {
        Schema::table('requisition_issuance_slips', fn (Blueprint $table) => $table->dropColumn('reservation_status'));
    }
};
