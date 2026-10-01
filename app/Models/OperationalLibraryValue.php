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
        'dispatch_driver' => 'Driver (Transported By)',
        'dispatch_received_by' => 'Received By',
        'transportation_source' => 'Transportation Source',
        'program_activity_type' => 'Program / Activity Type',
        'incident_type' => 'Disaster / Incident Type',
        'drrs_signatory' => 'DRRS Signatories',
        'drims_signatory' => 'DRIMS Signatories',
        'rros_ris_signatory' => 'RROS RIS Signatories',
        'rros_dr_signatory' => 'RROS DR Signatories',
        'rros_stf_signatory' => 'RROS STF Signatories',
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
            'source_of_goods' => ['FO Stockpile/Prepo'],
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

        Incident::query()->pluck('name')->each(fn ($value) => self::add('incident_type', $value));
    }

    public static function groupedOptions(): array
    {
        return self::query()->where('is_active', true)->orderBy('value')->get()->groupBy('library_type')->map->pluck('value')->map->unique()->map->values()->toArray();
    }

    /**
     * Active library rows with metadata for multi-field dispatch contact pickers.
     *
     * @return list<array{id:int,value:string,label:string,metadata:array<string,mixed>}>
     */
    public static function catalogEntries(string $libraryType): array
    {
        $seen = [];

        return self::query()
            ->where('library_type', $libraryType)
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'value', 'metadata'])
            ->filter(function (self $row) use (&$seen): bool {
                $key = self::normalizeLibraryName($row->value);
                if ($key === '' || isset($seen[$key])) {
                    return false;
                }
                $seen[$key] = true;

                return true;
            })
            ->sortBy(fn (self $row): string => mb_strtolower((string) $row->value), SORT_NATURAL)
            ->values()
            ->map(function (self $row): array {
                $metadata = is_array($row->metadata) ? $row->metadata : [];

                return [
                    'id' => $row->id,
                    'value' => $row->value,
                    'label' => $row->value,
                    'metadata' => $metadata,
                ];
            })
            ->all();
    }

    public static function normalizeLibraryName(mixed $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '');
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

    public static function signatoryLibraryTypes(): array
    {
        return ['drrs_signatory', 'drims_signatory', 'rros_ris_signatory', 'rros_dr_signatory', 'rros_stf_signatory'];
    }

    public static function normalizeSignatoryEmployeeKey(mixed $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $name)) ?? '');
    }

    public function signatoryEmployeeKey(): string
    {
        $fromMeta = self::normalizeSignatoryEmployeeKey(data_get($this->metadata, 'employee_name'));
        if ($fromMeta !== '') {
            return $fromMeta;
        }

        $display = trim(explode('|', (string) $this->value, 2)[0] ?? '');
        $withoutSuffix = preg_replace('/,\s*[^,]+$/u', '', $display) ?? $display;

        return self::normalizeSignatoryEmployeeKey($withoutSuffix);
    }

    /**
     * Keep denormalized person fields in sync across signatory library rows
     * for the same employee (matched by normalized employee name).
     *
     * Syncs name/position/suffix/designation/office (and initials when provided).
     * Preserves per-row role, document type, and active flag.
     */
    public static function syncRelatedSignatoryPersonDetails(
        array $matchNames,
        array $person,
        ?int $exceptId = null,
    ): int {
        $keys = collect($matchNames)
            ->map(function ($name): string {
                $display = trim(explode('|', (string) $name, 2)[0] ?? '');
                $withoutSuffix = preg_replace('/,\s*[^,]+$/u', '', $display) ?? $display;

                return self::normalizeSignatoryEmployeeKey($withoutSuffix);
            })
            ->filter()
            ->unique()
            ->values();
        if ($keys->isEmpty()) {
            return 0;
        }

        $name = trim((string) ($person['name'] ?? ''));
        $position = trim((string) ($person['position'] ?? ''));
        $suffix = trim((string) ($person['suffix'] ?? ''));
        $designation = trim((string) ($person['designation'] ?? ''));
        $office = trim((string) ($person['office'] ?? ''));
        $initials = trim((string) ($person['initials'] ?? ''));
        if ($name === '' || $designation === '') {
            return 0;
        }

        $displayName = $name.($suffix !== '' ? ", {$suffix}" : '');
        $value = "{$displayName} | {$designation}";
        $updated = 0;

        self::query()
            ->whereIn('library_type', self::signatoryLibraryTypes())
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->orderBy('id')
            ->get()
            ->filter(fn (self $row): bool => $keys->contains($row->signatoryEmployeeKey()))
            ->each(function (self $row) use ($name, $position, $suffix, $designation, $office, $initials, $value, &$updated): void {
                $metadata = is_array($row->metadata) ? $row->metadata : [];
                $metadata['employee_name'] = $name;
                $metadata['position'] = $position;
                $metadata['designation'] = $designation;
                if ($suffix !== '') {
                    $metadata['suffix'] = $suffix;
                } else {
                    unset($metadata['suffix']);
                }
                if ($office !== '') {
                    $metadata['office'] = $office;
                }
                if ($initials !== '') {
                    $metadata['initials'] = $initials;
                }

                $row->fill([
                    'value' => $value,
                    'metadata' => array_filter($metadata, fn ($item) => $item !== null && $item !== ''),
                ])->save();
                $updated++;
            });

        return $updated;
    }

    /**
     * Resolve MyPortal-style office/unit/section text for a signatory employee.
     * Prefers an explicit value, then a local User match (profile office / SSO payload).
     */
    public static function resolveSignatoryOffice(string $employeeName, ?string $providedOffice = null): string
    {
        $office = preg_replace('/\s+/u', ' ', trim((string) $providedOffice)) ?? '';
        if ($office !== '') {
            return $office;
        }

        $key = self::normalizeSignatoryEmployeeKey($employeeName);
        if ($key === '') {
            return '';
        }

        $user = User::query()
            ->where('is_active', true)
            ->whereRaw('LOWER(TRIM(name)) = ?', [$key])
            ->first(['office', 'area_of_assignment', 'sso_profile_payload']);
        if (! $user) {
            return '';
        }

        $fromPayload = collect([
            data_get($user->sso_profile_payload, 'myportal.data.section'),
            data_get($user->sso_profile_payload, 'myportal.data.unit'),
            data_get($user->sso_profile_payload, 'myportal.data.program'),
            data_get($user->sso_profile_payload, 'myportal.data.office'),
            data_get($user->sso_profile_payload, 'myportal.data.division'),
        ])->first(fn ($value) => filled($value));

        $resolved = preg_replace('/\s+/u', ' ', trim((string) ($fromPayload ?: $user->office ?: $user->area_of_assignment))) ?? '';

        // Skip short role-style office codes (e.g. RROS/DRRS) that are not org units.
        if ($resolved !== '' && preg_match('/^[A-Z]{2,12}(?:\s+AA)?$/', $resolved)) {
            return '';
        }

        return $resolved;
    }

    /**
     * Persist missing metadata.office for active signatory rows when a User match exists.
     */
    public static function backfillMissingSignatoryOffices(): int
    {
        $updated = 0;
        self::query()
            ->whereIn('library_type', self::signatoryLibraryTypes())
            ->orderBy('id')
            ->get()
            ->each(function (self $row) use (&$updated): void {
                $metadata = is_array($row->metadata) ? $row->metadata : [];
                if (filled(data_get($metadata, 'office'))) {
                    return;
                }
                $name = (string) (data_get($metadata, 'employee_name') ?: trim(explode('|', (string) $row->value, 2)[0] ?? ''));
                $office = self::resolveSignatoryOffice($name);
                if ($office === '') {
                    return;
                }
                $metadata['office'] = $office;
                $row->fill([
                    'metadata' => array_filter($metadata, fn ($item) => $item !== null && $item !== ''),
                ])->save();
                $updated++;
            });

        return $updated;
    }
}
