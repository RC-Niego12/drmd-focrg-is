<?php

namespace App\Services;

use App\Models\DispatchPlan;
use App\Models\OperationalLibraryValue;
use App\Models\RequisitionIssuanceSlip;
use App\Support\InlinePdfFilename;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class RisDrDocumentPdfService
{
    public function __construct(
        private readonly InventoryBalanceService $inventoryBalances,
        private readonly RisReservationService $reservations,
    ) {}

    /** Page margin on all sides for DR DomPDF output (0.5in = 36pt). */
    public const PAGE_MARGIN_PT = 36;

    /** Page margin on all sides for RIS DomPDF output (0.25in = 18pt; official sheet). */
    public const RIS_PAGE_MARGIN_PT = 18;

    /**
     * Stream an advance (unsigned) RIS or DR DomPDF preview for a saved slip.
     */
    public function streamAdvancePreview(RequisitionIssuanceSlip $slip, string $kind, bool $inline = true): Response
    {
        $kind = strtolower(trim($kind));
        abort_unless(in_array($kind, ['ris', 'dr'], true), 404);

        $payload = $this->payloadFromSlip($slip);
        if ($kind === 'dr' && ! ($payload['has_dr'] ?? false)) {
            abort(404, 'DR advance printable is unavailable for this RIS.');
        }

        $view = $kind === 'dr' ? 'documents.dr' : 'documents.ris';
        $filename = $this->advancePreviewFilename($slip, $kind, $payload);

        // A4 portrait; margins via blade @page (DomPDF has no setPaper margin API).
        // RIS = 0.25in (official sheet); DR = 0.5in.
        $pageMargin = $kind === 'ris' ? '0.25in' : '0.5in';
        $pageMarginPt = $kind === 'ris' ? self::RIS_PAGE_MARGIN_PT : self::PAGE_MARGIN_PT;
        $pdf = Pdf::loadView($view, [
            ...$payload,
            'page_margin' => $pageMargin,
            'page_margin_pt' => $pageMarginPt,
        ])
            ->setPaper('a4', 'portrait')
            ->setOption('isHtml5ParserEnabled', true)
            ->setOption('isRemoteEnabled', true)
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('dpi', 96)
            ->setOption('isFontSubsettingEnabled', true);

        return $inline ? $pdf->stream($filename) : $pdf->download($filename);
    }

    /** Stream the Delivery Receipt assigned to one dispatch vehicle. */
    public function streamDispatchVehicleDr(DispatchPlan $dispatch, int $vehicleIndex, bool $inline = true): Response
    {
        $dispatch->loadMissing(['requisitionIssuanceSlip.allocationItems', 'items']);
        $slip = $dispatch->requisitionIssuanceSlip;
        abort_unless($slip, 404, 'The dispatch has no linked RIS.');

        $vehicles = array_values($dispatch->resolvedVehicleDetails());
        abort_unless(isset($vehicles[$vehicleIndex]), 404, 'Dispatch vehicle not found.');
        $vehicle = $vehicles[$vehicleIndex];
        $drNumber = trim((string) ($vehicle['dr_number'] ?? ''));
        abort_if($drNumber === '', 404, 'This vehicle does not have an assigned Delivery Receipt.');

        $payload = $this->payloadFromSlip($slip);
        $slipItems = collect($payload['items']);
        $loaded = collect($vehicle['loaded_items'] ?? [])->filter(fn ($row) => is_array($row));
        $items = $loaded->map(function (array $row) use ($slipItems): array {
            $id = $row['requisition_issuance_item_id'] ?? null;
            $name = trim((string) ($row['item_name'] ?? ''));
            $source = $slipItems->first(fn (array $item): bool => ($id && (int) ($item['requisition_issuance_item_id'] ?? 0) === (int) $id)
                || ($name !== '' && strcasecmp(trim((string) ($item['item_name'] ?? '')), $name) === 0)
            ) ?? [];

            return [
                ...$source,
                'item_name' => $name !== '' ? $name : ($source['item_name'] ?? 'Item'),
                'quantity' => (int) ($row['loaded_quantity'] ?? 0),
                'remarks' => $row['remarks'] ?? ($source['remarks'] ?? null),
            ];
        })->filter(fn (array $item): bool => (int) ($item['quantity'] ?? 0) > 0)->values()->all();

        $modes = array_values(array_filter([(string) ($vehicle['mode_of_transportation'] ?? '')]));
        $payload['tracking'] = [
            ...$payload['tracking'],
            'dr_number' => $drNumber,
            'driver_name' => $vehicle['driver'] ?? null,
            'driver_contact_number' => $vehicle['driver_contact_number'] ?? null,
            'vehicle_plate_number' => $vehicle['vehicle_plate_number'] ?? null,
            'escort_name' => $vehicle['escort_name'] ?? null,
            'escort_contact_number' => $vehicle['escort_contact_number'] ?? null,
            'mode_of_transportation' => $modes,
            'transport_mode' => $modes,
            'received_by' => $vehicle['received_by'] ?? null,
            'delivered_at' => $vehicle['received_at'] ?? $vehicle['actual_arrival'] ?? null,
        ];
        $payload['items'] = $items;
        $payload['has_dr'] = true;

        $pdf = Pdf::loadView('documents.dr', [
            ...$payload,
            'page_margin' => '0.5in',
            'page_margin_pt' => self::PAGE_MARGIN_PT,
            'dispatch_vehicle_number' => $vehicleIndex + 1,
        ])->setPaper('a4', 'portrait')
            ->setOption('isHtml5ParserEnabled', true)
            ->setOption('isRemoteEnabled', true)
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('dpi', 96)
            ->setOption('isFontSubsettingEnabled', true);

        $filename = InlinePdfFilename::fromCandidates($drNumber, 'DR-'.$dispatch->id.'-'.($vehicleIndex + 1));

        return $inline ? $pdf->stream($filename) : $pdf->download($filename);
    }

    /** Stream the DR for recipient-held stock released without a transporter. */
    public function streamDispatchLocalHandoverDr(DispatchPlan $dispatch, bool $inline = true): Response
    {
        $dispatch->loadMissing(['requisitionIssuanceSlip.allocationItems', 'items']);
        $slip = $dispatch->requisitionIssuanceSlip;
        abort_unless($slip, 404, 'The dispatch has no linked RIS.');

        $handover = is_array($dispatch->local_handover_details) ? $dispatch->local_handover_details : [];
        $drNumber = trim((string) ($handover['dr_number'] ?? ''));
        abort_if($drNumber === '', 404, 'The local warehouse release does not have an assigned Delivery Receipt.');

        $payload = $this->payloadFromSlip($slip);
        $warehouseId = $handover['source_warehouse_id'] ?? null;
        $warehouseName = trim((string) ($handover['source_warehouse_name'] ?? ''));
        $dispatchItems = $dispatch->items->filter(function ($item) use ($warehouseId, $warehouseName): bool {
            if ($warehouseId && (int) $item->warehouse_id === (int) $warehouseId) return true;
            return $warehouseName !== '' && strcasecmp(trim((string) $item->warehouse_name), $warehouseName) === 0;
        });
        $slipItems = collect($payload['items']);
        $payload['items'] = $dispatchItems->map(function ($item) use ($slipItems): array {
            $source = $slipItems->first(fn (array $row): bool =>
                (int) ($row['requisition_issuance_item_id'] ?? 0) === (int) $item->requisition_issuance_item_id
            ) ?? [];
            return [
                ...$source,
                'item_name' => $item->item_name,
                'unit' => $item->unit,
                'quantity' => (int) $item->allocated_quantity,
            ];
        })->filter(fn (array $item): bool => (int) ($item['quantity'] ?? 0) > 0)->values()->all();
        $payload['tracking'] = [
            ...$payload['tracking'],
            'dr_number' => $drNumber,
            'source_warehouse' => $warehouseName ?: null,
            'driver_name' => null,
            'driver_contact_number' => null,
            'vehicle_plate_number' => null,
            'escort_name' => null,
            'escort_contact_number' => null,
            'mode_of_transportation' => [],
            'transport_mode' => [],
            'received_by' => $handover['received_by'] ?? null,
            'delivered_at' => $handover['received_at'] ?? $handover['released_at'] ?? null,
            'fulfillment_note' => 'No transport needed — released and received at the recipient-held warehouse.',
        ];
        $payload['has_dr'] = true;

        $pdf = Pdf::loadView('documents.dr', [
            ...$payload,
            'page_margin' => '0.5in',
            'page_margin_pt' => self::PAGE_MARGIN_PT,
        ])->setPaper('a4', 'portrait')
            ->setOption('isHtml5ParserEnabled', true)
            ->setOption('isRemoteEnabled', true)
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('dpi', 96)
            ->setOption('isFontSubsettingEnabled', true);

        $filename = InlinePdfFilename::fromCandidates($drNumber, 'DR-'.$dispatch->id.'-LOCAL');

        return $inline ? $pdf->stream($filename) : $pdf->download($filename);
    }

    /**
     * Prefer official DRN, then RIS No. / DR#, then a system fallback.
     *
     * @param  array<string, mixed>  $payload
     */
    public function advancePreviewFilename(RequisitionIssuanceSlip $slip, string $kind, ?array $payload = null): string
    {
        $payload ??= $this->payloadFromSlip($slip);
        $tracking = is_array($payload['tracking'] ?? null) ? $payload['tracking'] : [];
        $drn = $slip->ris_drn ?: ($tracking['ris_drn'] ?? null);
        $drNumber = $slip->dr_number ?: ($tracking['dr_number'] ?? null);

        if (strtolower(trim($kind)) === 'dr') {
            return InlinePdfFilename::fromCandidates($drn, $drNumber, $slip->ris_number, 'DR-'.$slip->id);
        }

        return InlinePdfFilename::fromCandidates($drn, $slip->ris_number, 'RIS-'.$slip->id);
    }

    /**
     * @return array{
     *   form: array<string, mixed>,
     *   tracking: array<string, mixed>,
     *   items: list<array<string, mixed>>,
     *   signatories: array<string, array{name: string, position: string, designation: string, office: string}>,
     *   has_dr: bool
     * }
     */
    public function payloadFromSlip(RequisitionIssuanceSlip $slip): array
    {
        $tracking = is_array($slip->tracking_data) ? $slip->tracking_data : [];
        $items = $this->itemsFromSlip($slip)->all();
        $drNumber = $slip->dr_number ?: ($tracking['dr_number'] ?? null);
        $remarks = trim((string) ($slip->remarks ?? ''));

        return [
            // Google Sheet imports are historical transaction records. Their
            // source sheet does not authoritatively encode these system-only
            // document fields, so the transaction preview must leave them blank.
            'is_google_sheet_transaction' => $slip->sync_source === 'google_sheet',
            'form' => [
                'ris_number' => $slip->ris_number,
                'ris_date' => optional($slip->ris_date)?->format('Y-m-d'),
                'purpose_of_release' => $slip->purpose_of_release,
                'recipient' => $slip->recipient,
                'delivery_site' => $slip->delivery_site,
                'receiving_representative' => $slip->receiving_representative,
                'contact_number' => $slip->contact_number,
                'remarks' => $remarks !== '' ? $remarks : $slip->remarks,
            ],
            'tracking' => [
                ...$tracking,
                'ris_drn' => $slip->ris_drn ?: ($tracking['ris_drn'] ?? null),
                'dr_number' => $drNumber,
                'prepared_by_name' => $slip->prepared_by_name ?: ($tracking['prepared_by_name'] ?? null),
                'release_witnessed_by' => $slip->release_witnessed_by ?: ($tracking['release_witnessed_by'] ?? null),
                'delivered_at' => optional($slip->delivered_at)?->format('Y-m-d') ?: ($tracking['delivered_at'] ?? null),
                'returned_particulars' => $slip->returned_particulars ?: ($tracking['returned_particulars'] ?? null),
                'returned_quantity' => $slip->returned_quantity ?? ($tracking['returned_quantity'] ?? null),
                'returned_reason' => $slip->returned_reason ?: ($tracking['returned_reason'] ?? null),
                // Transportation is recorded in Dispatch, not during RIS/DR preparation.
                // Explicitly blank legacy tracking values so advance documents do not
                // appear to confirm a transport arrangement prematurely.
                'driver_name' => null,
                'driver_contact_number' => null,
                'vehicle_plate_number' => null,
                'source_warehouse' => $tracking['source_warehouse'] ?? null,
                'received_by' => $tracking['received_by'] ?? null,
                'fully_delivered' => $tracking['fully_delivered'] ?? null,
                'mode_of_transportation' => [],
                'transport_mode' => [],
                'fulfillment_note' => $tracking['fulfillment_note'] ?? null,
            ],
            'items' => $items,
            'signatories' => $this->resolveSignatories(
                $slip->prepared_by_name ?: ($tracking['prepared_by_name'] ?? null),
                $slip->release_witnessed_by ?: ($tracking['release_witnessed_by'] ?? null),
            ),
            'has_dr' => filled($drNumber),
        ];
    }

    /**
     * @return array<string, array{name: string, position: string, designation: string, office: string}>
     */
    public function resolveSignatories(?string $preparedByFallback = null, ?string $releaseWitnessFallback = null): array
    {
        $rows = OperationalLibraryValue::query()
            ->whereIn('library_type', ['rros_ris_signatory', 'rros_dr_signatory'])
            ->where('is_active', true)
            ->get(['library_type', 'value', 'context', 'metadata']);

        $pick = function (string $type, string $context, ?string $fallback = null) use ($rows): array {
            $row = $rows->first(fn ($entry) => $entry->library_type === $type && $entry->context === $context);
            [$savedName, $savedDesignation] = array_pad(explode('|', (string) ($row?->value ?? ''), 2), 2, '');
            $metadata = is_array($row?->metadata) ? $row->metadata : [];
            $employeeName = trim((string) ($metadata['employee_name'] ?? ''));
            $suffix = trim((string) ($metadata['suffix'] ?? ''));
            $name = $employeeName !== ''
                ? ($employeeName.($suffix !== '' ? ", {$suffix}" : ''))
                : (trim($savedName) !== '' ? trim($savedName) : (string) ($fallback ?? ''));

            return [
                'name' => mb_strtoupper($name),
                'position' => (string) ($metadata['position'] ?? ''),
                'designation' => (string) ($metadata['designation'] ?? trim($savedDesignation)),
                'office' => (string) ($metadata['office'] ?? $metadata['office_unit'] ?? $metadata['myportal_office'] ?? ''),
            ];
        };

        return [
            'requested_by' => $pick('rros_ris_signatory', 'requested_by', $preparedByFallback),
            'approved_by' => $pick('rros_ris_signatory', 'approved_by'),
            'issued_by' => $pick('rros_ris_signatory', 'issued_by', $releaseWitnessFallback),
            'issuance_approved_by' => $pick('rros_dr_signatory', 'issuance_approved_by', $releaseWitnessFallback),
            'released_by' => $pick('rros_dr_signatory', 'released_by', $preparedByFallback),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function itemsFromSlip(RequisitionIssuanceSlip $slip): Collection
    {
        $allocation = $slip->relationLoaded('allocationItems')
            ? $slip->allocationItems
            : $slip->allocationItems()->get();

        if ($allocation->isNotEmpty()) {
            $balances = $this->inventoryBalances->balanceRows();
            $reservedByOthers = $this->reservations->batchTotals($slip->id);

            return $allocation->map(function ($item) use ($balances, $reservedByOthers): array {
                $brand = trim((string) ($item->brand_description ?: '-'));
                $expiry = trim((string) ($item->expiry ?: 'N/A'));
                $itemKey = $this->itemKey((string) $item->item_name);
                $matching = $balances->filter(fn (array $row): bool => (int) ($row['warehouse_id'] ?? 0) === (int) $item->warehouse_id
                    && $this->itemKey((string) ($row['item'] ?? '')) === $itemKey
                    && strcasecmp(trim((string) ($row['brand_description'] ?? '-')), $brand) === 0
                    && strcasecmp(trim((string) ($row['expiry'] ?? 'N/A')), $expiry) === 0
                );
                $batchKey = implode('|', [
                    $item->warehouse_id,
                    $itemKey,
                    strtolower($brand),
                    strtolower($expiry),
                ]);
                $physical = $matching->sum(fn (array $row): float => max(0, (float) ($row['available_balance'] ?? 0)));
                $available = max(0, $physical - (float) $reservedByOthers->get($batchKey, 0));
                $cost = $matching->sum(fn (array $row): float => max(0, (float) ($row['cost'] ?? 0)));
                $unitCost = $physical > 0 && $cost > 0 ? $cost / $physical : null;

                return [
                    'requisition_issuance_item_id' => $item->id,
                    'item_name' => $item->item_name ?? 'Item',
                    'unit' => $item->unit ?? null,
                    'quantity' => (int) ($item->quantity ?? 0),
                    'remarks' => $this->allocationRemarks(
                        $item->remarks,
                        $brand,
                        $expiry,
                        $unitCost,
                        (string) ($item->unit ?? 'unit'),
                    ),
                    'warehouse_name' => $item->warehouse_name ?? null,
                    'warehouse_id' => $item->warehouse_id ?? null,
                    'brand_description' => $item->brand_description ?? null,
                    'expiry' => $item->expiry ?? null,
                    'wit_stock_balance' => $matching->isNotEmpty() ? $available : ($item->wit_stock_balance ?? null),
                    'unit_cost' => $unitCost ?? $item->unit_cost ?? $item->unit_price ?? null,
                ];
            })->values();
        }

        return collect(is_array($slip->items) ? $slip->items : [])->map(fn (array $item) => [
            'requisition_issuance_item_id' => $item['requisition_issuance_item_id'] ?? $item['id'] ?? null,
            'item_name' => $item['item_name'] ?? 'Item',
            'unit' => $item['unit'] ?? null,
            'quantity' => (int) ($item['quantity'] ?? $item['allocated_quantity'] ?? 0),
            'remarks' => $item['remarks'] ?? null,
            'warehouse_name' => $item['warehouse_name'] ?? null,
            'warehouse_id' => $item['warehouse_id'] ?? null,
            'brand_description' => $item['brand_description'] ?? null,
            'expiry' => $item['expiry'] ?? null,
            'wit_stock_balance' => $item['wit_stock_balance'] ?? null,
            'unit_cost' => $item['unit_cost'] ?? $item['unit_price'] ?? null,
        ])->values();
    }

    private function itemKey(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower($value)) ?? '';
    }

    private function allocationRemarks(?string $remarks, string $brand, string $expiry, ?float $unitCost, string $unit): ?string
    {
        $generated = array_filter([
            $brand !== '' && $brand !== '-' ? "Brand: {$brand}" : null,
            $this->expiryMonthLabel($expiry) !== '' ? 'Expiry: '.$this->expiryMonthLabel($expiry) : null,
            $unitCost !== null ? 'Unit price: '.$this->formatPeso($unitCost).'/'.rtrim($unit, 's').'.' : null,
        ]);
        $generatedText = $generated === [] ? '' : implode('; ', $generated);
        $existing = trim((string) $remarks);
        $userRemarks = preg_replace(
            '/^(?=(?:Brand|Brand\/Description|Expiry|Expiries|Unit price|Unit prices):).*?\.(?=\s+[A-Z]|$)\s*/u',
            '',
            $existing
        ) ?? $existing;

        return trim(implode(' ', array_filter([$generatedText, trim($userRemarks)]))) ?: null;
    }

    private function expiryMonthLabel(string $value): string
    {
        if ($this->readableDate($value) === '') {
            return '';
        }

        try {
            return Carbon::parse($value)->locale('en')->isoFormat('MMM YYYY');
        } catch (\Throwable) {
            return $value;
        }
    }

    public function readableDate(mixed $value, bool $weekday = false): string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '' || in_array(mb_strtolower($raw), ['-', 'n/a', 'na', 'not applicable', 'none', 'null'], true)) {
            return '';
        }
        try {
            $parsed = Carbon::parse(preg_match('/^\d{4}-\d{2}-\d{2}/', $raw) ? substr($raw, 0, 10) : $raw);
        } catch (\Throwable) {
            return $raw;
        }

        return $weekday
            ? $parsed->locale('en')->isoFormat('dddd, MMMM D, YYYY')
            : $parsed->locale('en')->isoFormat('MMMM D, YYYY');
    }

    public function formatQuantity(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $number = (float) $value;
        if (! is_finite($number)) {
            return '';
        }

        return number_format($number, 0, '.', ',');
    }

    public function withdrawalRemarks(array $items): string
    {
        $byWarehouse = [];
        foreach ($items as $item) {
            $warehouse = trim((string) ($item['warehouse_name'] ?? ''));
            if ($warehouse === '') {
                continue;
            }
            $byWarehouse[$warehouse] ??= [];
            if (! empty($item['item_name'])) {
                $byWarehouse[$warehouse][] = $item['item_name'].': '.$this->formatQuantity($item['quantity'] ?? 0);
            }
        }
        if ($byWarehouse === []) {
            return '';
        }
        $warehouseWord = count($byWarehouse) === 1 ? 'warehouse' : 'warehouse/s';
        $lines = [];
        $index = 1;
        foreach ($byWarehouse as $warehouse => $parts) {
            $detail = $parts !== [] ? ' - '.implode('; ', $parts).';' : '';
            $lines[] = "{$index}. {$warehouse}{$detail}";
            $index++;
        }

        return "To be withdrawn at the following {$warehouseWord}:\n".implode("\n", $lines);
    }

    public function documentRisItemRemarks(?string $remarks): string
    {
        return trim((string) $remarks);
    }

    public function documentDrItemRemarks(?string $remarks, mixed $expiry = null): string
    {
        $expiryLabel = $this->expiryMonthLabel((string) ($expiry ?? ''));
        if ($expiryLabel !== '') {
            return "Expiry: {$expiryLabel}.";
        }

        $lines = preg_split('/\n+/', (string) $remarks) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $withoutPrice = trim(preg_replace([
                '/\s*;?\s*Unit prices?:\s*[^.;\n]+/iu',
                '/\s*;?\s*Note:\s*Sea-travel fallback\.?/iu',
                '/\s*;\s*;+/u',
                '/^[\s;]+|[\s;]+$/u',
            ], ['', '', ';', ''], $line) ?? '');
            if ($withoutPrice === '') {
                continue;
            }
            if (preg_match('/^(\d+\.\s*(?:Qty:\s*[^;]+;\s*)?Expiry:\s*[^.;]+)/iu', $withoutPrice, $m)) {
                $out[] = rtrim($m[1], ".; \t").'.';

                continue;
            }
            if (preg_match('/^(Qty:\s*[^;]+;\s*Expiry:\s*[^.;]+)/iu', $withoutPrice, $m)) {
                $out[] = rtrim($m[1], ".; \t").'.';

                continue;
            }
            if (preg_match('/^((?:Expiry|Expiries):\s*[^.;]+)/iu', $withoutPrice, $m)) {
                $out[] = rtrim($m[1], ".; \t").'.';
            }
        }

        return implode("\n", $out);
    }

    public function documentDrUnitCost(array $item): ?float
    {
        $remarks = (string) ($item['remarks'] ?? '');
        $prices = [];
        if (preg_match_all('/Unit prices?:\s*([^.\n]+)/iu', $remarks, $matches)) {
            foreach ($matches[1] as $chunk) {
                if (preg_match_all('/[\d,]+(?:\.\d+)?/', (string) $chunk, $amounts)) {
                    foreach ($amounts[0] as $amount) {
                        $parsed = (float) str_replace(',', '', $amount);
                        if (is_finite($parsed) && $parsed >= 0) {
                            $prices[] = $parsed;
                        }
                    }
                }
            }
        }
        if ($prices !== []) {
            $unique = array_values(array_unique(array_map(fn ($p) => round($p, 4), $prices)));
            if (count($unique) === 1) {
                return $unique[0];
            }

            return array_sum($prices) / count($prices);
        }

        foreach ([$item['unit_cost'] ?? null, $item['unit_price'] ?? null] as $value) {
            $parsed = is_numeric($value) ? (float) $value : null;
            if ($parsed !== null && is_finite($parsed) && $parsed >= 0) {
                return $parsed;
            }
        }

        return null;
    }

    public function formatPeso(?float $value): string
    {
        if ($value === null || ! is_finite($value)) {
            return '';
        }

        return '₱'.number_format($value, 2, '.', ',');
    }

    public function stockAvailableMark(?array $item): array
    {
        if (! $item || ! array_key_exists('wit_stock_balance', $item) || $item['wit_stock_balance'] === null || $item['wit_stock_balance'] === '') {
            return ['yes' => '', 'no' => '', 'quantity' => ''];
        }
        $available = (int) $item['wit_stock_balance'];
        $hasStock = $available > 0;

        return [
            'yes' => $hasStock ? '✓' : '',
            'no' => $hasStock ? '' : '✓',
            'quantity' => $this->formatQuantity($available),
        ];
    }

    public function transportMark(array $modes, string $label): string
    {
        $selected = array_values(array_filter(array_map(
            static fn ($mode) => mb_strtolower(trim((string) $mode)),
            $modes
        )));
        if ($selected === []) {
            return '☐';
        }
        $needle = mb_strtolower($label);
        foreach ($selected as $selectedMode) {
            if ($selectedMode === $needle) {
                return '✓';
            }
            if ($needle === 'dswd-owned' && str_contains($selectedMode, 'dswd')) {
                return '✓';
            }
            if ($needle === 'service provider' && str_contains($selectedMode, 'service')) {
                return '✓';
            }
            if ($needle === 'government asset' && str_contains($selectedMode, 'government')) {
                return '✓';
            }
            if (
                in_array($needle, ['partner', 'partner lgu'], true)
                && ($selectedMode === 'partner' || str_contains($selectedMode, 'partner'))
            ) {
                return '✓';
            }
        }

        return '☐';
    }

    /**
     * @return list<string>
     */
    private function asStringList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(
                static fn ($item): string => trim((string) $item),
                $value
            ), static fn (string $item): bool => $item !== ''));
        }
        if ($value === null || $value === '') {
            return [];
        }
        $text = trim((string) $value);
        if ($text === '') {
            return [];
        }
        if (str_contains($text, ',')) {
            return array_values(array_filter(array_map(
                static fn (string $item): string => trim($item),
                explode(',', $text)
            ), static fn (string $item): bool => $item !== ''));
        }

        return [$text === 'Partner LGU' ? 'Partner' : $text];
    }
}
