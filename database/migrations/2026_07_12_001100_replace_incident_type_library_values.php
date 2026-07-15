<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        DB::table('operational_library_values')->where('library_type', 'incident_type')->delete();
        $values = [
            'Effects of Strong Winds', 'Effects of Big Waves', 'Whirlwind Incident', 'Effects of Thunderstorms',
            'Effects of Southwest Monsoon (Habagat)', 'Effects of Northeast Monsoon (Amihan)', 'Effects of Easterlies',
            'Effects of ITCZ', 'Effects of Shear Line', 'Effects of Tail-End of Cold Front',
            'Effects of Trough of Low Pressure', 'Effects of Tropical Cyclone', 'Effects of Low-Pressure Area (LPA)',
            'Effects of Tropical Depression', 'Effects of Tropical Storm', 'Effects of Severe Tropical Storm',
            'Effects of Typhoon', 'Effects of Super Typhoon', 'Social Disorganization/Displacement', 'Oil Spill Incident',
            'Landslide Incident', 'Mudslide Incident', 'Flooding Incident', 'Flashflood Incident', 'Fire Incident',
            'Earthquake Incident', 'Dry Spell/Drought/El Niño', 'La Niña', 'Armed Conflict', 'Volcanic Activity',
            'Volcanic Eruption', 'Prolonged Power Outage', 'Planned Event', 'Tornado Incident',
            'Combined Effects of 2 or more Weather Disturbances', 'Displacement',
        ];
        $now = now();
        foreach ($values as $index => $value) {
            DB::table('operational_library_values')->insert([
                'library_type' => 'incident_type', 'value' => $value, 'context' => 'all',
                'metadata' => json_encode(['sort_order' => $index + 1]), 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
    public function down(): void {}
};
