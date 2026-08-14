<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['prepared_by' => 'requested_by', 'reviewed_by' => 'issued_by'] as $from => $to) {
            DB::table('operational_library_values')
                ->where('library_type', 'rros_stf_signatory')
                ->where('context', $from)
                ->orderBy('id')
                ->get()
                ->each(function ($row) use ($to): void {
                    $duplicate = DB::table('operational_library_values')
                        ->where('library_type', 'rros_stf_signatory')
                        ->where('context', $to)
                        ->where('value', $row->value)
                        ->exists();

                    $duplicate
                        ? DB::table('operational_library_values')->where('id', $row->id)->delete()
                        : DB::table('operational_library_values')->where('id', $row->id)->update(['context' => $to, 'updated_at' => now()]);
                });
        }
    }

    public function down(): void
    {
        DB::table('operational_library_values')->where('library_type', 'rros_stf_signatory')->where('context', 'requested_by')->update(['context' => 'prepared_by']);
        DB::table('operational_library_values')->where('library_type', 'rros_stf_signatory')->where('context', 'issued_by')->update(['context' => 'reviewed_by']);
    }
};
