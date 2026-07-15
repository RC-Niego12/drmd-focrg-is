<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        DB::table('fni_library_items')->orderBy('id')->get()
            ->groupBy(fn ($row) => implode('|', [$row->item_category, $row->item_name, $row->brand_description]))
            ->each(function ($rows): void {
                DB::table('fni_library_items')->whereIn('id', $rows->pluck('id')->slice(1))->delete();
            });
    }
    public function down(): void {}
};
