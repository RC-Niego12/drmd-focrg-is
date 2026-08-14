<?php

namespace App\Http\Controllers;

use App\Models\InventoryTransaction;
use App\Models\WarehouseSheetImport;
use App\Services\WitReleaseCrossmatchService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FniIssuanceController extends Controller
{
    public function __invoke(Request $request, WitReleaseCrossmatchService $crossmatch): Response
    {
        $transactions = InventoryTransaction::query()
            ->with(['batch.item', 'batch.warehouse', 'user', 'sheetImport'])
            ->where('type', 'release')
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->get();

        $imports = WarehouseSheetImport::query()->where('import_status', 'imported')->get();
        $rows = $transactions->map(fn (InventoryTransaction $transaction): array => $this->row($transaction, $crossmatch, $imports))->values();

        return Inertia::render('Inventory/FniIssuances', [
            'rows' => $rows,
            'years' => $rows->pluck('year')->filter()->unique()->sortDesc()->values(),
            'generatedAt' => now()->format('M j Y, g:i A'),
        ]);
    }

    private function row(InventoryTransaction $transaction, WitReleaseCrossmatchService $crossmatch, $imports): array
    {
        $batch = $transaction->batch;
        $item = $batch?->item;
        $warehouse = $batch?->warehouse;
        $date = $transaction->transaction_date ?? $transaction->created_at;
        $quantity = (float) ($transaction->quantity ?? 0);
        $unitCost = (float) ($transaction->unit_cost ?? 0);
        $totalCost = (float) ($transaction->total_cost ?? ($quantity * $unitCost));

        $comparison = filled($transaction->transactionable_type)
            ? $crossmatch->compare($transaction, $imports)
            : ['status' => 'wit_only', 'label' => 'WIT record only', 'summary' => 'No system Dispatch release is linked to this WIT row.', 'fields' => []];

        return [
            'id' => $transaction->id,
            'date' => $date?->format('M j Y') ?? '-',
            'sort_date' => $date?->format('Y-m-d'),
            'year' => $date?->year,
            'expiry_month' => $batch?->expiration_date?->format('M Y') ?? '-',
            'expiry_sort' => $batch?->expiration_date?->format('Y-m-d'),
            'reference' => $this->firstFilled($transaction->reference_number, $transaction->ris_if_stf, $transaction->call_off_number, '-'),
            'dr_number' => $this->clean($transaction->reference_number, '-'),
            'ris_if_stf' => $this->clean($transaction->ris_if_stf, '-'),
            'warehouse' => $this->clean($warehouse?->display_name),
            'warehouse_name' => $this->clean($warehouse?->name),
            'warehouse_type' => $this->clean($warehouse?->warehouse_type),
            'province' => $this->clean($warehouse?->province),
            'district' => $this->clean($warehouse?->district),
            'municipality' => $this->clean($warehouse?->municipality),
            'partnership' => $this->clean($warehouse?->partnership),
            'category' => $this->issuanceCategory(
                $transaction->sheetImport?->raw_payload['item_category'] ?? $item?->category,
                $item?->name,
            ),
            'item' => $this->clean($item?->name),
            'brand' => $this->brand($batch?->brand_description),
            'unit' => $this->clean($item?->unit, '-'),
            'source_of_goods' => $this->clean($transaction->source_of_goods),
            'purpose' => $this->clean($transaction->purpose),
            'recipient' => $this->firstFilled($transaction->recipient, $transaction->delivery_site, 'Unspecified'),
            'delivery_site' => $this->clean($transaction->delivery_site, '-'),
            'expected_delivery_date' => $transaction->expected_delivery_date?->format('M j Y') ?? '-',
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'cost' => $totalCost,
            'personnel' => $this->firstFilled($transaction->user?->name, $transaction->encoded_by_email, 'Unspecified'),
            'encoded_at' => $transaction->encoded_at?->format('M j Y, g:i A') ?? $transaction->created_at?->format('M j Y, g:i A') ?? '-',
            'edited_at' => $transaction->edited_at?->format('M j Y, g:i A') ?? $transaction->updated_at?->format('M j Y, g:i A') ?? '-',
            'remarks' => $this->clean($transaction->remarks, '-'),
            'record_source' => $transaction->sheetImport ? 'WIT Data Entry' : 'System Dispatch',
            'reconciliation_status' => $transaction->reconciliation_status ?: ($transaction->sheetImport ? 'wit_only' : 'not_required'),
            'reconciled_at' => $transaction->reconciled_at?->format('M j Y, g:i A'),
            'crossmatch' => $comparison,
            'crossmatch_status' => $comparison['status'],
        ];
    }

    private function clean(?string $value, string $fallback = 'Unspecified'): string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? $fallback : $trimmed;
    }

    private function brand(?string $value): string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' || strcasecmp($trimmed, 'Unspecified') === 0 ? '-' : $trimmed;
    }

    private function issuanceCategory(?string $category, ?string $itemName): string
    {
        $normalizedCategory = strtolower(trim((string) $category));
        $normalizedItem = strtolower(trim((string) $itemName));

        if (str_contains($normalizedCategory, 'family food pack') || str_contains($normalizedItem, 'family food pack')) {
            return 'Family Food Packs';
        }

        if (in_array($normalizedCategory, ['food', 'food item', 'food items'], true)) {
            return 'Food Items';
        }

        if (in_array($normalizedCategory, ['non_food', 'non food item', 'non food items'], true)) {
            return 'Non-Food Items';
        }

        $displayCategories = [
            'family food packs' => 'Family Food Packs',
            'food items' => 'Food Items',
            'non food items' => 'Non-Food Items',
            'non-food items' => 'Non-Food Items',
            'other nfis' => 'Other NFIs',
            'indirect materials' => 'Indirect Materials',
            'raw materials' => 'Raw Materials',
        ];

        if (isset($displayCategories[$normalizedCategory])) {
            return $displayCategories[$normalizedCategory];
        }

        return $this->clean($category);
    }

    private function firstFilled(mixed ...$values): string
    {
        $fallback = array_pop($values);

        foreach ($values as $value) {
            $trimmed = trim((string) $value);

            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return (string) $fallback;
    }
}
