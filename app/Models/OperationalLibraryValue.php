<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalLibraryValue extends Model
{
    public const TYPES = [
        'system_name' => 'System Name',
        'source_of_goods' => 'Source of Goods',
        'transaction_purpose' => 'Transaction Purpose',
        'supplier_sender' => 'Supplier and Sender',
        'recipient_requesting_party' => 'Recipient and Requesting Party',
        'delivery_site' => 'Delivery Site',
        'transportation_mode' => 'Transportation Mode',
        'vehicle_type' => 'Vehicle Type',
        'transportation_source' => 'Transportation Source',
        'program_activity_type' => 'Program / Activity Type',
        'incident_type' => 'Disaster / Incident Type',
        'drrs_signatory' => 'DRRS Signatories',
        'drn_prefix' => 'Document Reference Number Prefixes',
        'response_letter_initials' => 'Response Letter Initials',
        'document_reference_type' => 'RROS Document / Reference Type',
        'stock_status' => 'Stock Status',
        'transaction_status' => 'Transaction Status',
    ];

    protected $fillable = ['library_type', 'value', 'context', 'metadata', 'is_active'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'is_active' => 'boolean'];
    }

    public static function add(string $type, mixed $value, string $context = 'all'): void
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '-' || mb_strlen($value) > 255) {
            return;
        }
        self::firstOrCreate(['library_type' => $type, 'value' => $value, 'context' => $context], ['is_active' => true]);
    }

    public static function populateDefaults(): void
    {
        $systemName = self::firstOrCreate(
            ['library_type' => 'system_name', 'context' => 'all'],
            ['value' => 'Disaster Response Information Management System (DRIMS)', 'is_active' => true, 'metadata' => ['short_name' => 'DRIMS']],
        );
        if (blank(data_get($systemName->metadata, 'short_name'))) {
            $systemName->update(['metadata' => ['short_name' => 'DRIMS']]);
        }
        $defaults = [
            'transportation_mode' => ['Land', 'Sea', 'Air'],
            'program_activity_type' => ['Food-for-Work', 'Non-Food-for-Work', 'Relief Distribution', 'Prepositioning', 'Replenishment', 'Emergency Augmentation', 'Office Use'],
            'document_reference_type' => ['RIS', 'STF', 'IF', 'Delivery Receipt', 'Call-Off', 'Purchase Order', 'Donation Reference'],
            'stock_status' => ['Available', 'Reserved', 'Near Expiry', 'Expired', 'Damaged'],
            'transaction_status' => ['Draft', 'Submitted', 'Approved', 'Released', 'Delivered', 'Cancelled'],
        ];
        foreach ($defaults as $type => $values) {
            foreach ($values as $value) {
                self::add($type, $value);
            }
        }
        foreach (['assessment', 'response_letter'] as $context) {
            self::add('drn_prefix', 'CARAGA-FO-DRMD-DRRMS-SS-REP', $context);
        }
        self::add('response_letter_initials', 'JSP/AAA/JLM/1628', 'response_letter');

        InventoryTransaction::query()->get()->each(function (InventoryTransaction $tx): void {
            self::add('source_of_goods', $tx->source_of_goods, $tx->type);
            self::add('transaction_purpose', $tx->purpose, $tx->type);
            self::add('supplier_sender', $tx->sender_supplier, $tx->type);
            self::add('recipient_requesting_party', $tx->recipient, $tx->type);
            self::add('delivery_site', $tx->delivery_site, $tx->type);
            foreach (['land', 'sea', 'air'] as $mode) {
                $details = $tx->transport_details[$mode] ?? [];
                self::add('vehicle_type', $details['type'] ?? null, $mode);
                self::add('transportation_source', $details['source'] ?? null, $mode);
            }
        });
        AssistanceRequest::query()->get()->each(function (AssistanceRequest $request): void {
            self::add('recipient_requesting_party', $request->requesting_agency, 'request');
            self::add('recipient_requesting_party', $request->lgu, 'request');
        });
        Incident::query()->pluck('name')->each(fn ($value) => self::add('incident_type', $value));
        DispatchPlan::query()->get()->each(function (DispatchPlan $dispatch): void {
            self::add('delivery_site', $dispatch->destination, 'dispatch');
            self::add('recipient_requesting_party', $dispatch->receiving_agency_lgu, 'dispatch');
        });
        self::populateWitDropdowns();
    }

    private static function populateWitDropdowns(): void
    {
        $path = storage_path('logs/sheet-WITLibraries.csv');
        if (! file_exists($path)) {
            return;
        }
        $handle = fopen($path, 'r');
        $header = true;
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($header) {
                $header = false;

                continue;
            }
            foreach ([3, 7] as $column) {
                self::addWitValue('recipient_requesting_party', $row[$column] ?? null, 'wit_dropdown');
            }
            foreach ([4, 6] as $column) {
                self::addWitValue('supplier_sender', $row[$column] ?? null, 'wit_dropdown');
            }
            self::addWitValue('delivery_site', $row[8] ?? null, 'wit_dropdown');
        }
        fclose($handle);
    }

    private static function addWitValue(string $type, mixed $value, string $context): void
    {
        $value = trim((string) $value);
        $upper = strtoupper($value);
        if (str_starts_with($upper, 'PROVINCE ') || str_starts_with($upper, 'WAREHOUSE NAME ') || in_array($upper, ['DELIVERY SITES', 'REQUESTING PARTY / RECEPIENT', 'SENDER / SUPPLIER', 'RECEPIENT LIST', 'SENDER'], true)) {
            return;
        }
        self::add($type, $value, $context);
    }

    public static function groupedOptions(): array
    {
        return self::query()->where('is_active', true)->orderBy('value')->get()->groupBy('library_type')->map->pluck('value')->map->unique()->map->values()->toArray();
    }

    public static function systemNameConfiguration(): array
    {
        $entry = self::query()
            ->where('library_type', 'system_name')
            ->where('is_active', true)
            ->latest('updated_at')
            ->first();
        $longName = trim((string) ($entry?->value ?: 'Disaster Response Information Management System (DRIMS)'));
        $shortName = trim((string) data_get($entry?->metadata, 'short_name'));

        return [
            'long_name' => $longName,
            'short_name' => $shortName !== '' ? $shortName : $longName,
        ];
    }
}
