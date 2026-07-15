<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('fni_library_items', function (Blueprint $table): void {
            $table->dropUnique('fni_library_unique_item');
            $table->string('item_category', 100)->change();
            $table->string('item_name', 191)->change();
            $table->string('brand_description', 191)->change();
            $table->string('unit_of_measure', 64)->default('unit')->after('brand_description');
            $table->unique(['item_category','item_name','brand_description','unit_of_measure'], 'fni_library_unique_item_uom');
        });
        DB::table('operational_library_values')->where('library_type', 'unit_of_measure')->delete();
    }
    public function down(): void {
        Schema::table('fni_library_items', function (Blueprint $table): void {
            $table->dropUnique('fni_library_unique_item_uom');
            $table->dropColumn('unit_of_measure');
            $table->string('item_category', 255)->change();
            $table->string('item_name', 255)->change();
            $table->string('brand_description', 255)->change();
            $table->unique(['item_category','item_name','brand_description'], 'fni_library_unique_item');
        });
    }
};
