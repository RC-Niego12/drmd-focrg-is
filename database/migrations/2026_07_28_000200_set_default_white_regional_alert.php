<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('regional_alerts')->exists()) {
            return;
        }

        $ocdUserId = DB::table('users')->where('username', 'ocd-caraga')->value('id');
        if (! $ocdUserId) {
            return;
        }

        DB::table('regional_alerts')->insert([
            'set_by' => $ocdUserId,
            'alert_level' => 'white',
            'incident_name' => null,
            'coverage' => 'Caraga Region',
            'reason' => 'Default regional monitoring status. LGU DROMIC / Situational Reports are due at 2:00 PM.',
            'effective_at' => now(),
            'expires_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('regional_alerts')
            ->where('alert_level', 'white')
            ->where('reason', 'Default regional monitoring status. LGU DROMIC / Situational Reports are due at 2:00 PM.')
            ->delete();
    }
};
