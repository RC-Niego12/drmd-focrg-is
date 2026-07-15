<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void { DB::table('operational_library_values')->where('library_type', 'carrier_provider')->delete(); }
    public function down(): void {}
};
