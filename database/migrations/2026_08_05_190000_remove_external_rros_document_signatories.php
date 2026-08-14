<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('operational_library_values')->where(function ($query): void {
            $query->where(fn ($row) => $row->where('library_type', 'rros_dr_signatory')->whereIn('context', ['transported_by', 'received_by']))
                ->orWhere(fn ($row) => $row->where('library_type', 'rros_stf_signatory')->where('context', 'received_by'));
        })->delete();
    }

    public function down(): void {}
};
