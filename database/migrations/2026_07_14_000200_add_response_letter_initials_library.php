<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('operational_library_values')->updateOrInsert(
            ['library_type' => 'response_letter_initials', 'value' => 'JSP/AAA/JLM/1628', 'context' => 'response_letter'],
            ['is_active' => true, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function down(): void
    {
        DB::table('operational_library_values')->where('library_type', 'response_letter_initials')->delete();
    }
};
