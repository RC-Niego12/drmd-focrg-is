<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fni_library_items')) {
            return;
        }

        DB::table('fni_library_items')
            ->whereRaw('lower(item_name) like ?', ['%family food pack%'])
            ->where('item_category', 'Food Items')
            ->delete();
    }

    public function down(): void
    {
        // Incorrect category records are intentionally not restored.
    }
};
