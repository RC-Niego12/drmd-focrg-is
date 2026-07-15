<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $legacy = DB::table('operational_library_values')
                ->where('library_type', 'system_name')
                ->where('value', 'DRMD FOCRG Integrated System')
                ->orderBy('id')
                ->get();

            if ($legacy->isEmpty()) {
                return;
            }

            $current = DB::table('operational_library_values')
                ->where('library_type', 'system_name')
                ->where('value', 'Disaster Response Information Management System (DRIMS)')
                ->first();

            if ($current) {
                DB::table('operational_library_values')->whereIn('id', $legacy->pluck('id'))->delete();

                return;
            }

            $primary = $legacy->first();
            DB::table('operational_library_values')->where('id', $primary->id)->update([
                'value' => 'Disaster Response Information Management System (DRIMS)',
                'metadata' => json_encode(['short_name' => 'DRIMS']),
                'is_active' => true,
                'updated_at' => now(),
            ]);
            DB::table('operational_library_values')
                ->whereIn('id', $legacy->pluck('id')->reject(fn ($id) => $id === $primary->id))
                ->delete();
        });
    }

    public function down(): void
    {
        // The legacy public name is intentionally not restored.
    }
};
