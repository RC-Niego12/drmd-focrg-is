<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        $groups = DB::table('fni_library_items')->orderBy('id')->get()->groupBy(fn ($row) => implode('|', [$row->item_category, $row->item_name, $row->brand_description]));
        foreach ($groups as $rows) {
            $keep = $rows->first();
            DB::table('fni_library_items')->whereIn('id', $rows->pluck('id')->slice(1))->delete();
            DB::table('fni_library_items')->where('id', $keep->id)->update(['unit_of_measure' => $this->unitFor($keep->item_name)]);
        }
    }
    private function unitFor(string $name): string {
        $name = strtolower(trim($name));
        if (str_contains($name, 'family food pack') || str_contains($name, 'faced form') || str_contains($name, 'ready to eat')) return 'box';
        if (str_contains($name, 'kit')) return 'kit';
        if (str_contains($name, 'tent')) return 'set';
        if (str_contains($name, 'water')) return 'bottle';
        if (str_contains($name, 'twine') || str_contains($name, 'tarpaulin')) return 'roll';
        return 'piece';
    }
    public function down(): void {}
};
