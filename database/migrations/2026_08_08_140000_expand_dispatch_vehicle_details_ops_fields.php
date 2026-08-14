<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill vehicle_details[0] from legacy plan-level logistics / release / transit / receipt columns.
 * No schema change — vehicle_details is already JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('dispatch_plans')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $details = $this->decodeJson($row->vehicle_details ?? null);
                    if ($details === []) {
                        $types = $this->decodeJson($row->vehicle_types ?? null);
                        $details = [[
                            'vehicle_type' => $types[0] ?? null,
                            'driver' => $row->driver ?: null,
                            'driver_contact_number' => $row->driver_contact_number ?: null,
                            'vehicle_plate_number' => $row->vehicle_plate_number ?: null,
                        ]];
                        $count = max(1, (int) ($row->number_of_vehicles ?: 1));
                        while (count($details) < $count) {
                            $index = count($details);
                            $details[] = [
                                'vehicle_type' => $types[$index] ?? null,
                                'driver' => null,
                                'driver_contact_number' => null,
                                'vehicle_plate_number' => null,
                            ];
                        }
                    }

                    $modes = $this->decodeJson($row->mode_of_transportation ?? null);
                    $items = DB::table('dispatch_plan_items')
                        ->where('dispatch_plan_id', $row->id)
                        ->orderBy('id')
                        ->get(['id', 'requisition_issuance_item_id', 'item_name', 'loaded_quantity', 'remarks']);

                    $first = is_array($details[0] ?? null) ? $details[0] : [];
                    $estimatedDeparture = null;
                    if (filled($first['estimated_departure'] ?? null)) {
                        $estimatedDeparture = $this->dateTimeLocal($first['estimated_departure']);
                    } elseif (filled($first['dispatch_date'] ?? null)) {
                        $estimatedDeparture = $this->dateOnly($first['dispatch_date']).'T08:00';
                    } elseif (filled($row->dispatch_date ?? null)) {
                        $estimatedDeparture = $this->dateOnly($row->dispatch_date).'T08:00';
                    }

                    $first = array_merge($first, array_filter([
                        'estimated_departure' => $estimatedDeparture,
                        'estimated_arrival' => $this->dateTimeLocal($row->estimated_arrival ?? null),
                        'mode_of_transportation' => $modes !== [] ? $modes : null,
                        'warehouse_released_at' => $this->dateTimeLocal($row->warehouse_released_at ?? null),
                        'warehouse_released_by' => $row->warehouse_released_by ?: null,
                        'release_witnessed_by' => $row->release_witnessed_by ?: null,
                        'loaded_at' => $this->dateTimeLocal($row->loaded_at ?? null),
                        'loading_remarks' => $row->loading_remarks ?: null,
                        'departed_at' => $this->dateTimeLocal($row->departed_at ?? null),
                        'actual_arrival' => $this->dateTimeLocal($row->actual_arrival ?? null),
                        'delivered_at' => $this->dateOnly($row->delivered_at ?? null),
                        'fully_delivered' => $this->yesNoLabel($row->fully_delivered ?? null),
                        'received_by' => $row->received_by ?: null,
                        'received_at' => $this->dateTimeLocal($row->received_at ?? null),
                        'receiver_contact' => $row->receiver_contact ?: null,
                        'receipt_acknowledged' => (bool) ($row->receipt_acknowledged ?? false),
                        'receipt_remarks' => $row->receipt_remarks ?: null,
                    ], fn ($value) => $value !== null && $value !== '' && $value !== []));

                    // Prefer existing per-vehicle values over plan-level backfill.
                    $existingFirst = is_array($details[0] ?? null) ? $details[0] : [];
                    foreach ($existingFirst as $key => $value) {
                        if ($value !== null && $value !== '' && $value !== []) {
                            $first[$key] = $value;
                        }
                    }

                    if (! isset($first['loaded_items']) || ! is_array($first['loaded_items']) || $first['loaded_items'] === []) {
                        $first['loaded_items'] = $items->map(fn ($item) => [
                            'requisition_issuance_item_id' => $item->requisition_issuance_item_id,
                            'item_name' => $item->item_name,
                            'loaded_quantity' => $item->loaded_quantity,
                            'remarks' => $item->remarks,
                        ])->values()->all();
                    }

                    $details[0] = $first;

                    for ($i = 1; $i < count($details); $i++) {
                        $rowDetail = is_array($details[$i]) ? $details[$i] : [];
                        if (! isset($rowDetail['loaded_items']) || ! is_array($rowDetail['loaded_items'])) {
                            $rowDetail['loaded_items'] = $items->map(fn ($item) => [
                                'requisition_issuance_item_id' => $item->requisition_issuance_item_id,
                                'item_name' => $item->item_name,
                                'loaded_quantity' => null,
                                'remarks' => null,
                            ])->values()->all();
                        }
                        if (! array_key_exists('mode_of_transportation', $rowDetail)) {
                            $rowDetail['mode_of_transportation'] = [];
                        }
                        if (! array_key_exists('receipt_acknowledged', $rowDetail)) {
                            $rowDetail['receipt_acknowledged'] = false;
                        }
                        $details[$i] = $rowDetail;
                    }

                    DB::table('dispatch_plans')
                        ->where('id', $row->id)
                        ->update([
                            'vehicle_details' => json_encode(array_values($details)),
                            'number_of_vehicles' => count($details),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Non-destructive: expanded keys in JSON are harmless if rolled back at app layer.
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function dateOnly(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr((string) $value, 0, 10);
    }

    private function dateTimeLocal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $text = str_replace(' ', 'T', (string) $value);

        return substr($text, 0, 16);
    }

    private function yesNoLabel(mixed $value): ?string
    {
        if ($value === true || $value === 1 || $value === '1' || $value === 'Yes') {
            return 'Yes';
        }
        if ($value === false || $value === 0 || $value === '0' || $value === 'No') {
            return 'No';
        }

        return null;
    }
};
