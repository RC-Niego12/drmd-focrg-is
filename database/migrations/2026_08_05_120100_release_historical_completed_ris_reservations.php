<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('requisition_issuance_slips')
            ->where('status', 'completed')
            ->where('reservation_status', 'active')
            ->update(['reservation_status' => 'released']);
    }

    public function down(): void
    {
        // Historical completion cannot safely be distinguished from a later manual release.
    }
};
