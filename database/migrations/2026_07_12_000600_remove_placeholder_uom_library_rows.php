<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void { DB::table('fni_library_items')->whereRaw('lower(trim(unit_of_measure)) = ?', ['unit'])->delete(); }
    public function down(): void {}
};
