<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        $this->deduplicate('operational_library_values', fn ($row) => strtolower($row->library_type.'|'.$this->normalize($row->value)), function ($keep, $rows): void {
            DB::table('operational_library_values')->whereIn('id', $rows->pluck('id')->reject(fn ($id) => $id === $keep->id))->delete();
            DB::table('operational_library_values')->where('id', $keep->id)->update(['value' => $this->normalize($keep->value), 'context' => 'all']);
        });
        $this->deduplicate('warehouse_library_values', fn ($row) => strtolower($row->library_type.'|'.$this->normalize($row->value).'|'.$row->applicability), function ($keep, $rows): void {
            DB::table('warehouse_library_values')->whereIn('id', $rows->pluck('id')->reject(fn ($id) => $id === $keep->id))->delete();
            DB::table('warehouse_library_values')->where('id', $keep->id)->update(['value' => $this->normalize($keep->value)]);
        });
        $this->deduplicate('fni_library_items', fn ($row) => strtolower(implode('|', [$this->normalize($row->item_category), $this->normalize($row->item_name), $this->normalize($row->brand_description), $this->normalize($row->unit_of_measure)])), function ($keep, $rows): void {
            DB::table('fni_library_items')->whereIn('id', $rows->pluck('id')->reject(fn ($id) => $id === $keep->id))->delete();
            DB::table('fni_library_items')->where('id', $keep->id)->update(['item_category'=>$this->normalize($keep->item_category),'item_name'=>$this->normalize($keep->item_name),'brand_description'=>$this->normalize($keep->brand_description),'unit_of_measure'=>$this->normalize($keep->unit_of_measure)]);
        });
    }
    private function deduplicate(string $table, callable $key, callable $apply): void { DB::table($table)->orderBy('id')->get()->groupBy($key)->each(fn ($rows) => $apply($rows->first(), $rows)); }
    private function normalize(mixed $value): string { return preg_replace('/\s+/u', ' ', trim((string) $value)) ?? ''; }
    public function down(): void {}
};
