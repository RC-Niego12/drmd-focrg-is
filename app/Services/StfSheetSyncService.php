<?php

namespace App\Services;

use App\Models\InventoryTransaction;
use App\Models\StfSheetTransaction;
use App\Models\StfSyncRun;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class StfSheetSyncService
{
    public function __construct(
        private GoogleSheetCsvService $csv,
        private AuditLogger $audit,
        private RealtimePublisher $realtime,
    ) {}

    public function trackingRows(int $limit = 200): Collection
    {
        return InventoryTransaction::query()
            ->with([
                'batch:id,inventory_item_id,warehouse_id,batch_number,brand_description,expiration_date',
                'batch.item:id,name,unit',
                'batch.warehouse:id,name',
                'user:id,name',
            ])
            ->whereNotNull('ris_if_stf')
            ->where('ris_if_stf', '!=', '')
            ->latest('transaction_date')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (InventoryTransaction $row): array => [
                'id' => $row->id,
                'stf_reference' => $row->ris_if_stf,
                'transaction_date' => optional($row->transaction_date)?->toDateString(),
                'type' => $row->type,
                'purpose' => $row->purpose,
                'item' => $row->batch?->item?->name,
                'unit' => $row->batch?->item?->unit,
                'quantity' => $row->quantity,
                'batch_number' => $row->batch?->batch_number,
                'brand' => $row->batch?->brand_description,
                'expiry' => optional($row->batch?->expiration_date)?->toDateString(),
                'warehouse' => $row->batch?->warehouse?->name,
                'recipient' => $row->recipient,
                'delivery_site' => $row->delivery_site,
                'purpose' => $row->purpose,
                'remarks' => $row->remarks,
                'encoded_by' => $row->user?->name ?: $row->encoded_by_email,
                'synced_at' => optional($row->edited_at ?: $row->encoded_at)?->toIso8601String(),
            ]);
    }

    public function transactionRows(int $limit = 1000): Collection
    {
        return StfSheetTransaction::query()
            ->latest('stf_date')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (StfSheetTransaction $row): array => [
                'id' => $row->id,
                'stf_reference' => $row->stf_number,
                'transaction_date' => optional($row->stf_date)?->toDateString(),
                'recipient' => $row->recipient,
                'delivery_site' => $row->delivery_site,
                'warehouse' => data_get($row->tracking_data, 'source_warehouse'),
                'encoded_by' => data_get($row->tracking_data, 'prepared_by'),
                'total_quantity' => collect($row->items)->sum(fn (array $item): float => (float) ($item['quantity'] ?? 0)),
                'item_count' => count($row->items ?? []),
                'items' => $row->items ?? [],
                'tracking' => $row->tracking_data ?? [],
                'status' => $row->status,
                'preview_url' => '/rros/stf/preview-pdf?reference='.rawurlencode($row->stf_number),
            ]);
    }

    public function run(string $trigger = 'automatic', ?int $userId = null): array
    {
        $lock = Cache::lock('stf.sheet.sync', 600);
        if (! $lock->get()) {
            throw new RuntimeException('An STF synchronization is already running.');
        }

        $run = StfSyncRun::create([
            'started_by' => $userId,
            'trigger' => $trigger,
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $sheetId = trim((string) config('services.google_sheets.stf_tracking_spreadsheet_id', ''));
            $sheetRows = 0;
            $changes = [];

            if ($sheetId !== '') {
                $gid = (string) config('services.google_sheets.stf_tracking_gid', '1367120220');
                $trackingRows = $this->rows($this->csv->fetch($sheetId, $gid));
                $itemRows = $this->rows($this->csv->fetch($sheetId, (string) config('services.google_sheets.stf_items_gid', '1927904915')));
                $sheetRows = $this->importSheetTransactions($trackingRows, $itemRows);
                $changes[] = [
                    'source' => 'google_sheet',
                    'action' => 'scanned',
                    'rows' => $sheetRows,
                ];
            }

            $operational = $this->trackingRows(500);
            $seen = $operational->count();
            $result = [
                'trigger' => $trigger,
                'rows_seen' => $seen + $sheetRows,
                'records_created' => 0,
                'records_updated' => $seen,
                'items_synced' => $seen,
                'changed' => $seen > 0 || $sheetRows > 0,
                'source' => $sheetId !== '' ? 'google_sheet_and_operational' : 'operational',
            ];

            $run->update([
                ...collect($result)->except(['changed', 'source'])->all(),
                'status' => 'completed',
                'changes' => array_slice([
                    ...$changes,
                    ['source' => 'operational', 'action' => 'refreshed', 'rows' => $seen],
                ], 0, 500),
                'completed_at' => now(),
            ]);

            $this->audit->log('stf.sync.completed', $run, [], $result, $userId);
            $this->realtime->usersChanged(
                User::role(['RROS', 'RROS AA', 'Super Admin'])->pluck('id'),
                'stf.synced',
                ['run_id' => $run->id, 'rows_seen' => $result['rows_seen']]
            );

            return $result;
        } catch (\Throwable $exception) {
            $publicMessage = str_contains($exception->getMessage(), 'already running')
                ? $exception->getMessage()
                : ($exception->getMessage() ?: 'STF synchronization failed. No data was changed.');
            $run->update([
                'status' => 'failed',
                'error_message' => $publicMessage,
                'completed_at' => now(),
            ]);
            $this->audit->log('stf.sync.failed', $run, [], ['trigger' => $trigger, 'message' => $publicMessage], $userId);
            throw new RuntimeException($publicMessage, previous: $exception);
        } finally {
            $lock->release();
        }
    }

    private function rows(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);
        fgetcsv($handle, null, ',', '"', '\\');
        $rows = [];
        while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    private function importSheetTransactions(array $trackingRows, array $itemRows): int
    {
        $itemsByStf = collect($itemRows)
            ->map(function (array $columns): ?array {
                $reference = $this->text($columns[4] ?? null);
                if ($reference === '') {
                    return null;
                }

                return [
                    'stf_reference' => $reference,
                    'unit' => $this->text($columns[1] ?? null),
                    'item' => $this->text($columns[2] ?? null),
                    'quantity' => $this->number($columns[3] ?? null),
                    'receiving_warehouse' => $this->text($columns[6] ?? null),
                    'receiving_ownership' => $this->text($columns[7] ?? null),
                    'warehouse' => $this->text($columns[8] ?? null),
                    'warehouse_ownership' => $this->text($columns[9] ?? null),
                    'expiry' => $this->date($columns[10] ?? null),
                    'status' => $this->text($columns[20] ?? null),
                    'remarks' => $this->text($columns[21] ?? null),
                ];
            })
            ->filter()
            ->groupBy('stf_reference');

        $seen = 0;
        foreach ($trackingRows as $offset => $columns) {
            $reference = $this->text($columns[8] ?? null);
            if ($reference === '') {
                continue;
            }
            $seen++;
            $items = $itemsByStf->get($reference, collect())->map(fn (array $item) => collect($item)->except('stf_reference')->all())->values()->all();
            $forwarded = $this->bool($columns[34] ?? null);
            $fullyDelivered = $this->bool($columns[29] ?? null);
            StfSheetTransaction::updateOrCreate(['stf_number' => $reference], [
                'stf_date' => $this->date($columns[4] ?? null),
                'recipient' => $this->text($columns[11] ?? null),
                'delivery_site' => $this->text($columns[10] ?? null),
                'purpose' => $this->text($columns[2] ?? null) ?: $this->text($columns[1] ?? null),
                'status' => match (true) {
                    $fullyDelivered && $forwarded => 'Fully Delivered and Forwarded to Accounting',
                    $fullyDelivered => 'Fully Delivered',
                    $forwarded => 'Forwarded to Accounting',
                    default => 'Recorded',
                },
                'source_row_number' => (string) ($offset + 2),
                'tracking_data' => [
                    'prepared_by' => $this->text($columns[3] ?? null),
                    'receiving_representative' => $this->text($columns[12] ?? null),
                    'receiving_contact' => $this->text($columns[13] ?? null),
                    'delivered_at' => $this->date($columns[14] ?? null),
                    'release_witnessed_by' => $this->text($columns[16] ?? null),
                    'escort_name' => $this->text($columns[17] ?? null),
                    'escort_contact' => $this->text($columns[18] ?? null),
                    'driver_name' => $this->text($columns[19] ?? null),
                    'vehicle_plate_number' => $this->text($columns[20] ?? null),
                    'driver_license_number' => $this->text($columns[21] ?? null),
                    'driver_contact' => $this->text($columns[22] ?? null),
                    'received_by' => $this->text($columns[23] ?? null),
                    'receiver_contact' => $this->text($columns[24] ?? null),
                    'receiver_position' => $this->text($columns[25] ?? null),
                    'received_date' => $this->date($columns[26] ?? null),
                    'received_time' => $this->text($columns[27] ?? null),
                    'source_warehouse' => data_get($items, '0.warehouse'),
                    'accomplished_stf_link' => $this->text($columns[38] ?? null),
                ],
                'items' => $items,
                'sheet_synced_at' => now(),
            ]);
        }

        return $seen;
    }

    private function text(mixed $value): string
    {
        $value = trim((string) $value);

        return in_array(strtolower($value), ['', '-', 'n/a', 'na'], true) ? '' : $value;
    }

    private function number(mixed $value): ?float
    {
        $value = preg_replace('/[^0-9.\-]/', '', (string) $value);

        return $value === '' ? null : (float) $value;
    }

    private function bool(mixed $value): bool
    {
        return in_array(strtolower($this->text($value)), ['yes', 'true', '1', 'fully delivered'], true);
    }

    private function date(mixed $value): ?string
    {
        try {
            return $this->text($value) === '' ? null : Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
