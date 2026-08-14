<?php

namespace App\Services;

use App\Models\AssistanceRequest;
use App\Models\RequisitionIssuanceSlip;
use App\Models\RisSyncRun;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class RisSheetSyncService
{
    public function __construct(private GoogleSheetCsvService $csv, private AuditLogger $audit, private RealtimePublisher $realtime) {}

    public function run(string $trigger = 'automatic', ?int $userId = null): array
    {
        $lock = Cache::lock('ris.sheet.sync', 600);
        if (! $lock->get()) {
            throw new RuntimeException('An RIS Google Sheet synchronization is already running.');
        }

        $run = RisSyncRun::create(['started_by' => $userId, 'trigger' => $trigger, 'status' => 'running', 'started_at' => now()]);
        try {
            $sheetId = (string) config('services.google_sheets.ris_tracking_spreadsheet_id');
            if ($sheetId === '') {
                throw new RuntimeException('RIS tracking Google Sheet is not configured.');
            }
            $trackingRows = $this->rows($this->csv->fetch($sheetId, (string) config('services.google_sheets.ris_tracking_gid', '1905199506')));
            $itemRows = $this->rows($this->csv->fetch($sheetId, (string) config('services.google_sheets.ris_items_gid', '482002664')));
            $changes = [];
            $created = $updated = $seen = 0;

            foreach ($trackingRows as $offset => $columns) {
                $seen++;
                $risNumber = $this->text($columns[33] ?? null);
                $date = $this->date($columns[28] ?? null);
                if ($risNumber === '' || ! $date) {
                    continue;
                }
                $existing = RequisitionIssuanceSlip::where('ris_number', $risNumber)->first();
                // System-authored RIS records are authoritative operational data.
                // A historical Google Sheet row may reuse the same RIS number;
                // never overlay its recipient, purpose, dates, request link, or
                // e-PIRMA workflow onto the live system record.
                if ($existing?->sync_source === 'system') {
                    continue;
                }
                $assessmentDrn = $this->text($columns[22] ?? null);
                $linkedRequestId = $existing?->request_id ?: AssistanceRequest::where('assessment_drn', $assessmentDrn)->value('id');
                $values = [
                    'request_id' => $linkedRequestId,
                    'items' => $existing?->items ?: [],
                    'ris_date' => $date, 'purpose_of_release' => $this->text($columns[26] ?? null) ?: 'Not specified',
                    'recipient' => $this->text($columns[38] ?? null) ?: 'Not specified', 'delivery_site' => $this->text($columns[37] ?? null),
                    'receiving_representative' => $this->text($columns[39] ?? null), 'contact_number' => $this->text($columns[40] ?? null),
                    'assessment_drn_for_ris' => $assessmentDrn, 'purpose_of_request' => $this->text($columns[23] ?? null),
                    'incident_type' => $this->text($columns[24] ?? null), 'incident_specification' => $this->text($columns[25] ?? null),
                    'prepared_by_name' => $this->text($columns[27] ?? null), 'dr_number' => $this->text($columns[34] ?? null) ?: null,
                    'ris_drn' => $this->text($columns[35] ?? null), 'item_category' => $this->text($columns[36] ?? null),
                    'ardo_endorsed_at' => $this->date($columns[41] ?? null), 'ardo_returned_at' => $this->date($columns[42] ?? null),
                    'delivered_at' => $this->date($columns[43] ?? null), 'release_witnessed_by' => $this->text($columns[45] ?? null),
                    'driver_name' => $this->text($columns[46] ?? null), 'driver_contact_number' => $this->text($columns[47] ?? null),
                    'vehicle_plate_number' => $this->text($columns[48] ?? null), 'received_by' => $this->text($columns[49] ?? null),
                    'date_received' => $this->date($columns[50] ?? null), 'fully_delivered' => $this->bool($columns[52] ?? null),
                    'has_returned_items' => $this->bool($columns[53] ?? null), 'returned_particulars' => $this->text($columns[54] ?? null),
                    'returned_quantity' => $this->number($columns[55] ?? null), 'returned_reason' => $this->text($columns[56] ?? null),
                    'forwarded_to_accounting' => $this->bool($columns[57] ?? null), 'forwarded_to_accounting_at' => $this->date($columns[58] ?? null),
                    'accounting_received_by' => $this->text($columns[60] ?? null), 'assessment_link' => $this->text($columns[61] ?? null),
                    'ris_link' => $this->text($columns[62] ?? null), 'rds_link' => $this->text($columns[63] ?? null), 'csmr_link' => $this->text($columns[64] ?? null),
                    'status' => $this->bool($columns[57] ?? null) ? 'completed' : 'prepared',
                    'reservation_status' => $existing?->sync_source === 'system'
                        ? ($existing->reservation_status ?? 'active')
                        : 'released',
                    'source_row_number' => $offset + 2,
                    'sync_source' => $existing?->sync_source === 'system' ? 'system' : 'google_sheet',
                    'sheet_synced_at' => now(),
                ];
                $slip = RequisitionIssuanceSlip::updateOrCreate(['ris_number' => $risNumber], $values);
                $hasChanges = ! $existing || collect($slip->getChanges())->except(['sheet_synced_at', 'updated_at'])->isNotEmpty();
                if (! $existing) {
                    $created++;
                } elseif ($hasChanges) {
                    $updated++;
                }
                if ($hasChanges) {
                    $changes[] = ['ris_number' => $risNumber, 'action' => $existing ? 'updated' : 'created'];
                }
            }

            $items = 0;
            foreach ($itemRows as $offset => $columns) {
                $risNumber = $this->text($columns[4] ?? null);
                $itemName = $this->text($columns[2] ?? null);
                $quantity = $this->number($columns[3] ?? null);
                $slip = $risNumber !== '' ? RequisitionIssuanceSlip::where('ris_number', $risNumber)->first() : null;
                if (! $slip || $itemName === '' || ! $quantity) {
                    continue;
                }
                if ($slip->sync_source === 'system') {
                    continue;
                }
                $warehouseName = $this->text($columns[8] ?? null);
                $requestItemId = $slip->request_id
                    ? AssistanceRequest::find($slip->request_id)?->items()->where('item_name', $itemName)->value('id')
                    : null;
                $warehouseId = $warehouseName !== ''
                    ? Warehouse::where('name', $warehouseName)->orWhere('name', 'like', "%{$warehouseName}%")->value('id')
                    : null;
                $slip->allocationItems()->updateOrCreate(['source_row_number' => $offset + 2], [
                    'request_item_id' => $requestItemId, 'warehouse_id' => $warehouseId,
                    'unit' => $this->text($columns[1] ?? null), 'item_name' => $itemName, 'quantity' => $quantity,
                    'warehouse_name' => $warehouseName, 'warehouse_type' => $this->text($columns[9] ?? null),
                    'allocation_guide' => $this->number($columns[11] ?? null), 'wit_stock_balance' => $this->number($columns[12] ?? null),
                    'remaining_balance' => $this->number($columns[14] ?? null), 'allocation_status' => $this->text($columns[15] ?? null),
                ]);
                $items++;
            }

            $result = ['trigger' => $trigger, 'rows_seen' => $seen, 'records_created' => $created, 'records_updated' => $updated, 'items_synced' => $items, 'changed' => $created > 0 || $changes !== []];
            $run->update([...$result, 'status' => 'completed', 'changes' => array_slice($changes, 0, 500), 'completed_at' => now()]);
            $this->audit->log('ris.sync.completed', $run, [], $result, $userId);
            $this->realtime->usersChanged(User::role(['RROS', 'RROS AA'])->pluck('id'), 'ris.sync.completed', $result);

            return $result;
        } catch (\Throwable $exception) {
            $publicMessage = str_contains($exception->getMessage(), 'already running')
                ? $exception->getMessage()
                : 'RIS/DR sync could not reach Google Sheets after several attempts. No data was changed. Please try again shortly.';
            $run->update(['status' => 'failed', 'error_message' => $publicMessage, 'completed_at' => now()]);
            $this->audit->log('ris.sync.failed', $run, [], ['trigger' => $trigger, 'message' => $publicMessage], $userId);
            $this->realtime->usersChanged(User::role(['RROS', 'RROS AA'])->pluck('id'), 'ris.sync.failed', ['message' => $publicMessage]);
            throw new RuntimeException($publicMessage, previous: $exception);
        } finally {
            $lock->release();
        }
    }

    public function history(int $limit = 20)
    {
        return RisSyncRun::with('user:id,name')->latest('started_at')->limit($limit)->get();
    }

    private function rows(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);
        fgetcsv($handle);
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        } fclose($handle);

        return $rows;
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

    private function bool(mixed $value): ?bool
    {
        $value = strtolower($this->text($value));

        return $value === '' ? null : in_array($value, ['yes', 'true', '1', 'fully delivered'], true);
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
