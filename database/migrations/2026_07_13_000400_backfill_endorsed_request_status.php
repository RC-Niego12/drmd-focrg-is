<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('requests')
            ->where('endorsed_to_drrs', true)
            ->whereIn('submission_type', ['fni_request', 'proposal'])
            ->where('status', 'submitted')
            ->whereNull('incident_id')
            ->update(['status' => 'endorsed', 'updated_at' => now()]);
    }

    public function down(): void {}
};
