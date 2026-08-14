<?php

namespace App\Services;

use App\Models\InventoryTransaction;
use App\Models\WarehouseSheetImport;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class WitReleaseCrossmatchService
{
    /** @param Collection<int, WarehouseSheetImport> $imports */
    public function compare(InventoryTransaction $system, Collection $imports): array
    {
        $linked = $system->sheetImport;
        $candidate = $linked ?: $this->bestCandidate($system, $imports);
        if (! $candidate) {
            return ['status' => 'no_wit_record', 'label' => 'No WIT counterpart', 'summary' => 'No likely WIT issuance row was found.', 'fields' => []];
        }

        $wit = is_array($candidate->raw_payload) ? $candidate->raw_payload : [];
        $transport = is_array($system->transport_details) ? $system->transport_details : [];
        $land = is_array($transport['land'] ?? null) ? $transport['land'] : [];
        $batch = $system->batch;
        $fields = [
            $this->field('Warehouse', $batch?->warehouse?->display_name ?: $batch?->warehouse?->name, $wit['warehouse_name'] ?? null),
            $this->field('Item', $batch?->item?->name, $wit['item'] ?? null),
            $this->field('Brand', $batch?->brand_description, $wit['brand_description'] ?? null, optional: true),
            $this->field('Expiry', $batch?->expiration_date?->format('Y-m'), $this->month($wit['issuance_expiry'] ?? null), optional: true),
            $this->field('Transaction date', $system->transaction_date?->format('Y-m-d'), $this->date($wit['transaction_date'] ?? null)),
            $this->field('Source of Goods', $system->source_of_goods, $wit['source_of_goods'] ?? null),
            $this->field('Purpose', $system->purpose, $wit['purpose'] ?? null),
            $this->field('RIS / STF', $system->ris_if_stf, $wit['ris_if_stf'] ?? null),
            $this->field('Vehicle DR', $system->reference_number, $wit['reference_dr'] ?? null),
            $this->field('Unit', $batch?->item?->unit, $wit['uom'] ?? null),
            $this->numberField('Issuance quantity', $system->quantity, $wit['issuance'] ?? null),
            $this->numberField('Unit cost', $system->unit_cost, $wit['issuance_unit_cost'] ?? null, optional: true),
            $this->numberField('Total cost', $system->total_cost, $wit['issuance_cost'] ?? null, optional: true),
            $this->field('Recipient', $system->recipient, $wit['recipient'] ?? null),
            $this->field('Delivery site', $system->delivery_site, $wit['delivery_site'] ?? null),
            $this->field('Expected delivery date', $system->expected_delivery_date?->format('Y-m-d'), $this->date($wit['expected_delivery_date'] ?? null)),
            $this->field('Land transportation type', $land['type'] ?? null, $wit['land_transportation_type'] ?? null),
            $this->field('Land transportation source', $land['source'] ?? null, $wit['land_transportation_source'] ?? null),
            $this->field('Plate number', $land['plate'] ?? null, $wit['plate'] ?? null),
            $this->field('Driver', $land['driver'] ?? null, $wit['driver'] ?? null),
            $this->field('Driver contact', $land['contact_number'] ?? null, $wit['contact_number'] ?? null),
        ];
        $mismatches = collect($fields)->where('match', false)->values();
        $status = $mismatches->isEmpty() ? 'matched' : 'mismatch';

        return [
            'status' => $status,
            'label' => $status === 'matched' ? 'System and WIT matched' : $mismatches->count().' field mismatch(es)',
            'summary' => $status === 'matched'
                ? 'The concerned system and WIT release values match.'
                : 'Review the highlighted values before treating these records as reconciled.',
            'wit_row' => $candidate->sheet_row_number,
            'wit_import_id' => $candidate->id,
            'candidate' => ! $linked,
            'fields' => $fields,
            'mismatch_fields' => $mismatches->pluck('label')->all(),
        ];
    }

    /** @param Collection<int, WarehouseSheetImport> $imports */
    private function bestCandidate(InventoryTransaction $system, Collection $imports): ?WarehouseSheetImport
    {
        $item = $this->normalize($system->batch?->item?->name);
        $ris = $this->normalize($system->ris_if_stf);
        $dr = $this->normalize($system->reference_number);

        return $imports->filter(fn (WarehouseSheetImport $row): bool => (float) ($row->raw_payload['issuance'] ?? 0) > 0)
            ->map(function (WarehouseSheetImport $row) use ($item, $ris, $dr): array {
                $raw = $row->raw_payload ?? [];
                $score = 0;
                if ($ris !== '' && $this->normalize($raw['ris_if_stf'] ?? null) === $ris) $score += 45;
                if ($dr !== '' && $this->normalize($raw['reference_dr'] ?? null) === $dr) $score += 40;
                if ($item !== '' && $this->normalize($raw['item'] ?? null) === $item) $score += 20;
                return [$row, $score];
            })->filter(fn (array $entry): bool => $entry[1] >= 40)
            ->sortByDesc(fn (array $entry): int => $entry[1])
            ->first()[0] ?? null;
    }

    private function field(string $label, mixed $system, mixed $wit, bool $optional = false): array
    {
        $a = trim((string) $system); $b = trim((string) $wit);
        $match = $optional && $a === '' && $b === '' ? true : $this->normalize($a) === $this->normalize($b);
        return ['label' => $label, 'system' => $a !== '' ? $a : '—', 'wit' => $b !== '' ? $b : '—', 'match' => $match];
    }

    private function numberField(string $label, mixed $system, mixed $wit, bool $optional = false): array
    {
        $a = $this->number($system); $b = $this->number($wit);
        $bothBlank = trim((string) $system) === '' && trim((string) $wit) === '';
        return ['label' => $label, 'system' => $a, 'wit' => $b, 'match' => ($optional && $bothBlank) || abs($a - $b) < 0.01];
    }

    private function normalize(mixed $value): string { return mb_strtolower(preg_replace('/[^a-z0-9]+/i', '', trim((string) $value)) ?? ''); }
    private function number(mixed $value): float { return round((float) str_replace([',', '₱', ' '], '', (string) $value), 2); }
    private function date(mixed $value): string { try { return filled($value) ? Carbon::parse($value)->format('Y-m-d') : ''; } catch (\Throwable) { return trim((string) $value); } }
    private function month(mixed $value): string { try { return filled($value) ? Carbon::parse('01 '.trim((string) $value))->format('Y-m') : ''; } catch (\Throwable) { return trim((string) $value); } }
}
