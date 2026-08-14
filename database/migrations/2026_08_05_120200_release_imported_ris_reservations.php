<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('requisition_issuance_slips')
            ->where('sync_source', 'google_sheet')
            ->where('reservation_status', 'active')
            ->update(['reservation_status' => 'released']);
    }

    public function down(): void
    {
        // Imported tracking history must not become a planning reservation.
    }
};
