<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_plans', function (Blueprint $table): void {
            $table->json('vehicle_details')->nullable()->after('number_of_vehicles');
        });

        DB::table('dispatch_plans')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $existing = $row->vehicle_details ?? null;
                    if (filled($existing)) {
                        continue;
                    }

                    $types = $this->decodeJsonList($row->vehicle_types ?? null);
                    $details = [[
                        'vehicle_type' => $types[0] ?? null,
                        'vehicle_id' => $row->vehicle_id ? (int) $row->vehicle_id : null,
                        'driver' => $row->driver ?: null,
                        'driver_contact_number' => $row->driver_contact_number ?: null,
                        'vehicle_plate_number' => $row->vehicle_plate_number ?: null,
                    ]];

                    $count = max(1, (int) ($row->number_of_vehicles ?: 1));
                    while (count($details) < $count) {
                        $index = count($details);
                        $details[] = [
                            'vehicle_type' => $types[$index] ?? null,
                            'vehicle_id' => null,
                            'driver' => null,
                            'driver_contact_number' => null,
                            'vehicle_plate_number' => null,
                        ];
                    }

                    $hasAny = collect($details)->contains(function (array $detail): bool {
                        return filled($detail['vehicle_type'])
                            || filled($detail['vehicle_id'])
                            || filled($detail['driver'])
                            || filled($detail['driver_contact_number'])
                            || filled($detail['vehicle_plate_number']);
                    });

                    if (! $hasAny && blank($row->number_of_vehicles)) {
                        continue;
                    }

                    DB::table('dispatch_plans')
                        ->where('id', $row->id)
                        ->update([
                            'vehicle_details' => json_encode($details),
                            'number_of_vehicles' => count($details),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('dispatch_plans', function (Blueprint $table): void {
            $table->dropColumn('vehicle_details');
        });
    }

    private function decodeJsonList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(
                fn ($item) => is_string($item) ? trim($item) : $item,
                $value,
            )));
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($item) => is_string($item) ? trim($item) : $item,
            $decoded,
        )));
    }
};
