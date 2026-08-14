<?php

namespace App\Services;

use App\Models\RequisitionIssuanceItem;
use Illuminate\Support\Collection;

class RisReservationService
{
    public function batchTotals(?int $excludeSlipId = null): Collection
    {
        return RequisitionIssuanceItem::query()
            ->select(['requisition_issuance_slip_id', 'warehouse_id', 'item_name', 'brand_description', 'expiry', 'quantity'])
            ->whereNotNull('warehouse_id')
            ->whereNotNull('brand_description')
            ->whereNotNull('expiry')
            ->whereHas('slip', fn ($query) => $query
                ->where('sync_source', 'system')
                ->where('reservation_status', 'active')
                ->where('status', '!=', 'cancelled')
                ->when($excludeSlipId, fn ($slips) => $slips->whereKeyNot($excludeSlipId)))
            ->get()
            ->groupBy(fn ($row): string => implode('|', [
                $row->warehouse_id,
                $this->itemKey($row->item_name),
                strtolower(trim((string) $row->brand_description)),
                strtolower(trim((string) $row->expiry)),
            ]))
            ->map(fn ($rows) => (float) $rows->sum('quantity'));
    }

    public function totals(?int $excludeSlipId = null): Collection
    {
        return RequisitionIssuanceItem::query()
            ->select(['id', 'requisition_issuance_slip_id', 'warehouse_id', 'item_name', 'brand_description', 'expiry', 'quantity'])
            ->whereNotNull('warehouse_id')
            ->whereHas('slip', fn ($query) => $query
                ->where('sync_source', 'system')
                ->where('reservation_status', 'active')
                ->where('status', '!=', 'cancelled')
                ->when($excludeSlipId, fn ($slips) => $slips->whereKeyNot($excludeSlipId)))
            ->get()
            ->groupBy(fn ($row) => $row->warehouse_id.'|'.$this->itemKey($row->item_name))
            ->map(fn ($rows) => (float) $rows->sum('quantity'));
    }

    /** Active RIS planning reservations rolled up by item key (warehouse-agnostic). */
    public function totalsByItem(?int $excludeSlipId = null): Collection
    {
        return $this->totals($excludeSlipId)
            ->reduce(function (Collection $carry, float $quantity, string $key): Collection {
                $itemKey = explode('|', $key, 2)[1] ?? '';
                if ($itemKey === '') {
                    return $carry;
                }

                $carry[$itemKey] = (float) ($carry[$itemKey] ?? 0) + $quantity;

                return $carry;
            }, collect());
    }

    public function payload(): array
    {
        return RequisitionIssuanceItem::query()
            ->select(['id', 'requisition_issuance_slip_id', 'warehouse_id', 'item_name', 'quantity'])
            ->with('slip:id,request_id,status,fully_delivered')
            ->whereNotNull('warehouse_id')
            ->whereHas('slip', fn ($query) => $query
                ->where('sync_source', 'system')
                ->where('reservation_status', 'active')
                ->where('status', '!=', 'cancelled'))
            ->get()
            ->groupBy(fn ($row) => implode('|', [
                $row->requisition_issuance_slip_id,
                $row->warehouse_id,
                $this->itemKey($row->item_name),
                strtolower(trim((string) $row->brand_description)),
                strtolower(trim((string) $row->expiry)),
            ]))
            ->map(function ($rows): array {
                $row = $rows->first();

                return [
                    'ris_id' => $row->requisition_issuance_slip_id,
                    'request_id' => $row->slip?->request_id,
                    'warehouse_id' => $row->warehouse_id,
                    'item_name' => $row->item_name,
                    'item_key' => $this->itemKey($row->item_name),
                    'brand_description' => $row->brand_description,
                    'expiry' => $row->expiry,
                    'quantity' => (float) $rows->sum('quantity'),
                ];
            })->values()->all();
    }

    private function itemKey(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower($value)) ?? '';
    }
}
