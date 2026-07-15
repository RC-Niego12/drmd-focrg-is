<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('operational_library_values')
            ->where('library_type', 'system_name')
            ->orderBy('id')
            ->get()
            ->each(function ($row): void {
                $metadata = json_decode((string) ($row->metadata ?? ''), true) ?: [];
                $metadata['short_name'] ??= 'DRIMS';
                DB::table('operational_library_values')
                    ->where('id', $row->id)
                    ->update(['metadata' => json_encode($metadata)]);
            });
    }

    public function down(): void
    {
        DB::table('operational_library_values')
            ->where('library_type', 'system_name')
            ->orderBy('id')
            ->get()
            ->each(function ($row): void {
                $metadata = json_decode((string) ($row->metadata ?? ''), true) ?: [];
                unset($metadata['short_name']);
                DB::table('operational_library_values')
                    ->where('id', $row->id)
                    ->update(['metadata' => $metadata === [] ? null : json_encode($metadata)]);
            });
    }
};
