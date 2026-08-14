<?php

namespace App\Services;

use App\Http\Controllers\FniLibraryController;
use App\Models\FniLibraryItem;
use App\Models\OperationalLibraryValue;
use App\Models\WarehouseLibraryValue;
use Illuminate\Support\Facades\DB;

class LibraryRecoveryService
{
    public function recoverLocal(): array
    {
        $before = $this->counts();
        WarehouseLibraryValue::populateDefaults();
        OperationalLibraryValue::populateDefaults();
        app(FniLibraryController::class)->recoverFromInventory();
        $this->restoreIncidentTypes();
        $this->restoreDrrsSignatories();
        $this->deduplicateOperationalValues();

        return ['before' => $before, 'after' => $this->counts()];
    }

    public function counts(): array
    {
        return ['fni' => FniLibraryItem::count(), 'warehouse' => WarehouseLibraryValue::count(), 'operational' => OperationalLibraryValue::count()];
    }

    private function restoreIncidentTypes(): void
    {
        $values = [
            'Effects of Strong Winds', 'Effects of Big Waves', 'Whirlwind Incident', 'Effects of Thunderstorms',
            'Effects of Southwest Monsoon (Habagat)', 'Effects of Northeast Monsoon (Amihan)', 'Effects of Easterlies', 'Effects of ITCZ',
            'Effects of Shear Line', 'Effects of Tail-End of Cold Front', 'Effects of Trough of Low Pressure', 'Effects of Tropical Cyclone',
            'Effects of Low-Pressure Area (LPA)', 'Effects of Tropical Depression', 'Effects of Tropical Storm', 'Effects of Severe Tropical Storm',
            'Effects of Typhoon', 'Effects of Super Typhoon', 'Social Disorganization/Displacement', 'Oil Spill Incident', 'Landslide Incident',
            'Mudslide Incident', 'Flooding Incident', 'Flashflood Incident', 'Fire Incident', 'Earthquake Incident', 'Dry Spell/Drought/El Niño',
            'La Niña', 'Armed Conflict', 'Volcanic Activity', 'Volcanic Eruption', 'Prolonged Power Outage', 'Planned Event', 'Tornado Incident',
            'Combined Effects of 2 or more Weather Disturbances', 'Displacement',
        ];
        foreach ($values as $index => $value) {
            OperationalLibraryValue::updateOrCreate(
                ['library_type' => 'incident_type', 'value' => $value, 'context' => 'all'],
                ['metadata' => ['sort_order' => $index + 1], 'is_active' => true]
            );
        }
    }

    private function restoreDrrsSignatories(): void
    {
        foreach ([
            [
                'value' => 'ALDIE MAE A. ANDOY | OIC- DRMD Chief',
                'context' => 'reviewed_by',
                'document_type' => 'assessment',
                'employee_name' => 'ALDIE MAE A. ANDOY',
                'position' => 'Social Welfare Officer IV',
                'designation' => 'OIC- DRMD Chief',
                'initials' => 'AAA',
            ],
            [
                'value' => 'JEAN PAUL S. PARAJES, RSW, MSSW | Assistant Regional Director for Operations',
                'context' => 'approved_by',
                'document_type' => 'assessment',
                'employee_name' => 'JEAN PAUL S. PARAJES',
                'position' => 'Assistant Regional Director for Operations',
                'suffix' => 'RSW, MSSW',
                'designation' => 'Assistant Regional Director for Operations',
                'initials' => 'JSP',
            ],
            [
                'value' => 'JEAN PAUL S. PARAJES, RSW, MSSW | Assistant Regional Director for Operations',
                'context' => 'approved_by',
                'document_type' => 'response_letter',
                'employee_name' => 'JEAN PAUL S. PARAJES',
                'position' => 'Assistant Regional Director for Operations',
                'suffix' => 'RSW, MSSW',
                'designation' => 'Assistant Regional Director for Operations',
                'initials' => 'JSP',
            ],
        ] as $row) {
            OperationalLibraryValue::updateOrCreate(
                [
                    'library_type' => 'drrs_signatory',
                    'value' => $row['value'],
                    'context' => $row['context'],
                ],
                [
                    'metadata' => array_filter([
                        'document_type' => $row['document_type'],
                        'employee_name' => $row['employee_name'],
                        'position' => $row['position'] ?? null,
                        'suffix' => $row['suffix'] ?? null,
                        'designation' => $row['designation'],
                        'initials' => $row['initials'] ?? null,
                    ]),
                    'is_active' => true,
                ]
            );
        }

        OperationalLibraryValue::query()
            ->where('library_type', 'drrs_signatory')
            ->where('context', 'prepared_by')
            ->delete();
    }

    private function deduplicateOperationalValues(): void
    {
        DB::table('operational_library_values')->where('library_type', '!=', 'drrs_signatory')->orderBy('id')->get()
            ->groupBy(fn ($row) => mb_strtolower($row->library_type.'|'.$this->normalize($row->value)))
            ->each(function ($rows): void {
                $keep = $rows->first();
                $remove = $rows->pluck('id')->reject(fn ($id) => $id === $keep->id);
                if ($remove->isNotEmpty()) {
                    DB::table('operational_library_values')->whereIn('id', $remove)->delete();
                }
                DB::table('operational_library_values')->where('id', $keep->id)->update(['value' => $this->normalize($keep->value), 'context' => 'all']);
            });
    }

    private function normalize(mixed $value): string
    {
        return preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';
    }
}
