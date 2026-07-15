<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('requests')
            ->where('assessment_status', 'final')
            ->update(['status' => 'acted']);
    }

    public function down(): void
    {
        DB::table('requests')
            ->where('assessment_status', 'final')
            ->where('status', 'acted')
            ->update(['status' => 'under_review']);
    }
};
