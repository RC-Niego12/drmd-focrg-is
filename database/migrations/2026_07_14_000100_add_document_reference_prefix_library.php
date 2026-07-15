<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach (['assessment', 'response_letter'] as $context) {
            DB::table('operational_library_values')->updateOrInsert(
                ['library_type' => 'drn_prefix', 'value' => 'CARAGA-FO-DRMD-DRRMS-SS-REP', 'context' => $context],
                ['metadata' => null, 'is_active' => true, 'updated_at' => $now, 'created_at' => $now],
            );
        }
    }

    public function down(): void
    {
        DB::table('operational_library_values')->where('library_type', 'drn_prefix')->delete();
    }
};
