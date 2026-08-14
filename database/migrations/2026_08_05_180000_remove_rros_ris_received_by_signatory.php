<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('operational_library_values')
            ->where('library_type', 'rros_ris_signatory')
            ->where('context', 'received_by')
            ->delete();
    }

    public function down(): void
    {
        // LGU receiving representatives are transactional data, not RROS library signatories.
    }
};
