<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('requests')->whereNotNull('assessment_form_data')->orderBy('id')->eachById(function ($request): void {
            $meta = json_decode((string) $request->assessment_form_data, true) ?: [];
            $incidents = collect($meta['incidents'] ?? []);
            if ($incidents->count() < 2 || ! empty($meta['incident_fni_allocations'])) return;

            $source = $request->source_lgu_dromic_request_id ? DB::table('requests')->find($request->source_lgu_dromic_request_id) : null;
            $sourcePayload = json_decode((string) ($source?->lgu_dromic_payload ?? ''), true) ?: [];
            $linked = collect($sourcePayload['linked_incidents'] ?? []);
            $items = DB::table('request_items')->where('request_id', $request->id)->orderBy('id')->get();
            if ($items->isEmpty()) return;

            $weights = $incidents->map(fn (array $incident): int => max(0, (int) ($incident['affected_families'] ?? 0)));
            if ($weights->sum() <= 0) $weights = $incidents->map(fn (): int => 1);

            $allocations = $incidents->map(function (array $incident, int $incidentIndex) use ($items, $linked, $weights): array {
                $sourceReference = (string) ($incident['source_reference'] ?? '');
                $linkedIncident = $linked->first(fn (array $row): bool =>
                    ($sourceReference !== '' && strcasecmp((string) ($row['incident_code'] ?? ''), $sourceReference) === 0)
                    || (string) ($row['occurrence_started_at'] ?? '') === (string) ($incident['occurrence_at'] ?? '')
                );

                return [
                    'incident_key' => (string) ($linkedIncident['series_key'] ?? ($sourceReference ?: 'incident-'.$incidentIndex)),
                    'source_reference' => $sourceReference ?: null,
                    'series_key' => $linkedIncident['series_key'] ?? null,
                    'incident_type' => $incident['incident_type'] ?? null,
                    'barangay' => $incident['barangay'] ?? null,
                    'allocation_basis' => 'legacy_affected_families',
                    'items' => $items->map(function ($item) use ($incidentIndex, $weights): array {
                        $quantity = (int) ($item->approved_quantity ?? $item->requested_quantity ?? 0);
                        $raw = $weights->map(fn (int $weight): float => $quantity * ($weight / $weights->sum()));
                        $floors = $raw->map(fn (float $value): int => (int) floor($value));
                        $remainder = max(0, $quantity - $floors->sum());
                        $order = $raw->keys()->sortByDesc(fn (int $index): float => $raw[$index] - floor($raw[$index]))->values();
                        $allocated = $floors[$incidentIndex] + ($order->take($remainder)->contains($incidentIndex) ? 1 : 0);

                        return [
                            'item_key' => strtolower(trim((string) ($item->fni_library_item_id ?: $item->item_name))),
                            'fni_library_item_id' => $item->fni_library_item_id,
                            'item_name' => $item->item_name,
                            'quantity' => $allocated,
                        ];
                    })->all(),
                ];
            })->all();

            $meta['incident_fni_allocations'] = $allocations;
            $meta['incident_fni_allocation_note'] = 'System-inferred for a legacy combined assessment using affected-family proportions. Review through the assessment correction workflow when actual incident quantities differ.';
            DB::table('requests')->where('id', $request->id)->update(['assessment_form_data' => json_encode($meta), 'updated_at' => now()]);
        });
    }

    public function down(): void
    {
        DB::table('requests')->whereNotNull('assessment_form_data')->orderBy('id')->eachById(function ($request): void {
            $meta = json_decode((string) $request->assessment_form_data, true) ?: [];
            if (! isset($meta['incident_fni_allocation_note'])) return;
            unset($meta['incident_fni_allocations'], $meta['incident_fni_allocation_note']);
            DB::table('requests')->where('id', $request->id)->update(['assessment_form_data' => json_encode($meta), 'updated_at' => now()]);
        });
    }
};
