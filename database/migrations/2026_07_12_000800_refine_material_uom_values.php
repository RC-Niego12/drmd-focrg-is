<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        DB::table('fni_library_items')->whereRaw('lower(trim(item_name)) = ?', ['rice'])->update(['unit_of_measure' => 'sack']);
        DB::table('fni_library_items')->whereRaw('lower(item_name) like ?', ['%packaging tape%'])->update(['unit_of_measure' => 'roll']);
    }
    public function down(): void {}
};
