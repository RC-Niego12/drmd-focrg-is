<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('requests')
            ->where('lgu_relief_request_reference', 'like', 'LGU-RA-%')
            ->update([
                'lgu_relief_request_reference' => DB::raw("REPLACE(lgu_relief_request_reference, 'LGU-RA-', 'LGU-REQ-')"),
            ]);
    }

    public function down(): void
    {
        DB::table('requests')
            ->where('lgu_relief_request_reference', 'like', 'LGU-REQ-%')
            ->update([
                'lgu_relief_request_reference' => DB::raw("REPLACE(lgu_relief_request_reference, 'LGU-REQ-', 'LGU-RA-')"),
            ]);
    }
};
