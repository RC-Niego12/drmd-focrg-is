<?php

namespace App\Services;

use App\Models\LguDirectoryEntry;
use App\Models\LguDirectoryStaffMember;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class LguWarehousePersonnelSyncService
{
    /**
     * Seed RROS warehouse WH focals / storekeepers into matching LGU profiles.
     * LGU profile edits (is_locally_updated) always supersede warehouse sync for that staff type.
     *
     * @return array{lgus_updated:int,focals_synced:int,storekeepers_synced:int,warehouses_matched:int}
     */
    public function syncFromWarehouses(?Collection $warehouses = null): array
    {
        $warehouses ??= $this->lguWarehouseQuery()
            ->get([
                'id',
                'name',
                'external_warehouse_id',
                'municipality',
                'province',
                'partnership',
                'ownership',
                'contact_person',
                'contact_number',
                'email',
                'designated_storekeepers',
                'storekeeper_contact_number',
            ]);

        $directories = LguDirectoryEntry::query()
            ->where('is_active', true)
            ->get(['id', 'lgu_name', 'override_lgu_name', 'psgc_code', 'source_sheet']);

        $byPsgc = $directories->filter(fn (LguDirectoryEntry $entry) => filled($entry->psgc_code))
            ->keyBy(fn (LguDirectoryEntry $entry) => $this->normalizePsgc($entry->psgc_code));

        $byPlace = $directories->groupBy(
            fn (LguDirectoryEntry $entry) => $this->placeKey(
                $entry->override_lgu_name ?: $entry->lgu_name,
                $this->provinceFromSourceSheet($entry->source_sheet),
            )
        );

        $grouped = [];
        $matchedWarehouseIds = [];

        foreach ($warehouses as $warehouse) {
            $directory = $this->resolveDirectory($warehouse, $byPsgc, $byPlace);
            if (! $directory) {
                continue;
            }

            $matchedWarehouseIds[$warehouse->id] = true;
            $key = (string) $directory->id;
            $grouped[$key] ??= [
                'directory' => $directory,
                'focals' => [],
                'storekeepers' => [],
            ];

            $office = trim((string) ($warehouse->name ?: $warehouse->municipality)) ?: null;
            $focalName = $this->cleanPersonName((string) ($warehouse->contact_person ?? ''));
            if ($focalName !== '') {
                $focalKey = $this->personKey($focalName);
                $grouped[$key]['focals'][$focalKey] ??= [
                    'office' => $office,
                    'name' => $focalName,
                    'position' => 'Warehouse Focal',
                    'id_number' => null,
                    'contact_number' => $this->cleanContact((string) ($warehouse->contact_number ?? '')),
                    'email' => $this->cleanEmail((string) ($warehouse->email ?? '')),
                ];
            }

            $storekeeperContact = $this->cleanContact((string) ($warehouse->storekeeper_contact_number ?? ''));
            foreach ($this->splitPeople((string) ($warehouse->designated_storekeepers ?? '')) as $storekeeperName) {
                $storeKey = $this->personKey($storekeeperName);
                $grouped[$key]['storekeepers'][$storeKey] ??= [
                    'office' => $office,
                    'name' => $storekeeperName,
                    'position' => 'Warehouse Storekeeper',
                    'id_number' => null,
                    'contact_number' => $storekeeperContact,
                    'email' => null,
                ];
            }
        }

        $summary = [
            'lgus_updated' => 0,
            'focals_synced' => 0,
            'storekeepers_synced' => 0,
            'warehouses_matched' => count($matchedWarehouseIds),
        ];

        foreach ($grouped as $payload) {
            /** @var LguDirectoryEntry $directory */
            $directory = $payload['directory'];
            $focals = array_values($payload['focals']);
            $storekeepers = array_values($payload['storekeepers']);

            $summary['focals_synced'] += $this->replaceStaffType(
                $directory,
                LguDirectoryStaffMember::TYPE_WAREHOUSE_FOCAL,
                $focals,
            );
            $summary['storekeepers_synced'] += $this->replaceStaffType(
                $directory,
                LguDirectoryStaffMember::TYPE_WAREHOUSE_STOREKEEPER,
                $storekeepers,
            );
            $summary['lgus_updated']++;
        }

        return $summary;
    }

    /**
     * Whether this LGU has at least one matched RROS LGU warehouse.
     */
    public function directoryHasWarehouses(LguDirectoryEntry $directory): bool
    {
        return $this->warehousesForDirectory($directory, partnershipLguOnly: false)->isNotEmpty();
    }

    /**
     * Resolve the LGU directory for a portal user (linked personnel, then PSGC).
     */
    public function directoryForUser(?User $user): ?LguDirectoryEntry
    {
        if (! $user) {
            return null;
        }

        $linked = app(LguPersonnelAccountService::class)->findLinkedPersonnel($user);
        if ($linked && filled($linked['entry_id'] ?? null)) {
            $directory = LguDirectoryEntry::query()->find((int) $linked['entry_id']);
            if ($directory) {
                return $directory;
            }
        }

        $psgc = $this->normalizePsgc($user->lgu_psgc_code);
        if ($psgc !== '') {
            $match = LguDirectoryEntry::query()
                ->where('is_active', true)
                ->get(['id', 'lgu_name', 'override_lgu_name', 'psgc_code', 'source_sheet', 'lgu_level', 'is_active'])
                ->first(fn (LguDirectoryEntry $entry) => $this->normalizePsgc($entry->psgc_code) === $psgc);
            if ($match) {
                return $match;
            }
        }

        return null;
    }

    /**
     * Active warehouses belonging to the user's LGU.
     * When $partnershipLguOnly is true, only partnership = LGU rows are included.
     *
     * @return Collection<int, Warehouse>
     */
    public function warehousesForUser(?User $user, bool $partnershipLguOnly = true): Collection
    {
        $directory = $this->directoryForUser($user);

        return $directory
            ? $this->warehousesForDirectory($directory, $partnershipLguOnly)
            : collect();
    }

    /**
     * Active warehouses matched to a directory (same PSGC / place rules as personnel sync).
     *
     * @return Collection<int, Warehouse>
     */
    public function warehousesForDirectory(LguDirectoryEntry $directory, bool $partnershipLguOnly = true): Collection
    {
        $byPsgc = collect([$directory])
            ->filter(fn (LguDirectoryEntry $entry) => filled($entry->psgc_code))
            ->keyBy(fn (LguDirectoryEntry $entry) => $this->normalizePsgc($entry->psgc_code));

        $byPlace = collect([$directory])->groupBy(
            fn (LguDirectoryEntry $entry) => $this->placeKey(
                $entry->override_lgu_name ?: $entry->lgu_name,
                $this->provinceFromSourceSheet($entry->source_sheet),
            )
        );

        $query = $partnershipLguOnly
            ? Warehouse::query()->whereRaw('LOWER(TRIM(COALESCE(partnership, ""))) = ?', ['lgu'])
            : $this->lguWarehouseQuery();

        return $query
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->filter(fn (Warehouse $warehouse) => $this->resolveDirectory($warehouse, $byPsgc, $byPlace)?->is($directory))
            ->values();
    }

    /**
     * @return list<int>
     */
    public function warehouseIdsForUser(?User $user, bool $partnershipLguOnly = true): array
    {
        return $this->warehousesForUser($user, $partnershipLguOnly)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function lguWarehouseQuery()
    {
        return Warehouse::query()->where(function ($query): void {
            $query->whereRaw('LOWER(TRIM(COALESCE(partnership, ""))) = ?', ['lgu'])
                ->orWhereRaw('LOWER(TRIM(COALESCE(ownership, ""))) in (?, ?, ?)', ['municipal', 'city', 'provincial']);
        });
    }

    /**
     * @param  list<array{office:?string,name:string,position:?string,id_number:?string,contact_number:?string,email:?string}>  $rows
     */
    private function replaceStaffType(LguDirectoryEntry $directory, string $staffType, array $rows): int
    {
        $existing = $directory->staffMembers()
            ->where('staff_type', $staffType)
            ->orderBy('sort_order')
            ->get();

        // Once the LGU saves focals/storekeepers on their profile, those rows win
        // and warehouse sync must not replace or delete them.
        $localMembers = $existing->where('is_locally_updated', true)->values();
        if ($localMembers->isNotEmpty()) {
            return $localMembers->count();
        }

        $existingByName = $existing->keyBy(
            fn (LguDirectoryStaffMember $member) => $this->personKey($member->name)
        );
        $keptIds = [];

        foreach (array_values($rows) as $index => $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $key = $this->personKey($name);
            $member = $existingByName->get($key);
            $values = [
                'staff_type' => $staffType,
                'sort_order' => $index,
                'office' => $row['office'] ?? null,
                'name' => $name,
                'position' => $row['position'] ?? null,
                'id_number' => $row['id_number'] ?? null,
                'contact_number' => $row['contact_number'] ?? null,
                'email' => $row['email'] ?? null,
                'is_locally_updated' => false,
            ];

            if ($member) {
                $member->forceFill($values)->save();
            } else {
                $member = $directory->staffMembers()->create($values);
            }

            $this->reuseExistingDirectoryLogin($directory, $member);

            $keptIds[] = $member->id;
        }

        $query = $directory->staffMembers()->where('staff_type', $staffType);
        if ($keptIds === []) {
            $query->delete();
        } else {
            $query->whereNotIn('id', $keptIds)->delete();
        }

        return count($keptIds);
    }

    /**
     * If the warehouse person matches an existing LGU official/alternate/officer/staff
     * with a portal login, reuse that same User instead of inventing a second identity.
     */
    private function reuseExistingDirectoryLogin(LguDirectoryEntry $directory, LguDirectoryStaffMember $member): void
    {
        if (filled($member->user_id)) {
            return;
        }

        $key = $this->personKey($member->name);
        if ($key === '') {
            return;
        }

        $directory->loadMissing(['officials', 'ldrrmoOfficers', 'lswdoAlternates', 'staffMembers']);

        $candidates = collect()
            ->merge($directory->officials)
            ->merge($directory->lswdoAlternates)
            ->merge($directory->ldrrmoOfficers)
            ->merge($directory->staffMembers->where('id', '!=', $member->id));

        $match = $candidates->first(function ($person) use ($key): bool {
            $name = trim((string) ($person->override_name ?? $person->name ?? ''));

            return $name !== '' && $this->personKey($name) === $key && filled($person->user_id);
        });

        if (! $match) {
            return;
        }

        $member->forceFill([
            'user_id' => $match->user_id,
            'login_username' => $match->login_username,
            'email' => $member->email ?: ($match->email ?? $match->email_address ?? null),
            'contact_number' => $member->contact_number ?: ($match->contact_number ?? $match->mobile_number ?? null),
            'id_number' => $member->id_number ?: ($match->id_number ?? null),
        ])->save();
    }

    /**
     * @param  Collection<string, LguDirectoryEntry>  $byPsgc
     * @param  Collection<string, Collection<int, LguDirectoryEntry>>  $byPlace
     */
    private function resolveDirectory(Warehouse $warehouse, Collection $byPsgc, Collection $byPlace): ?LguDirectoryEntry
    {
        $psgc = $this->municipalityPsgcFromWarehouse($warehouse);
        if ($psgc !== '' && $byPsgc->has($psgc)) {
            return $byPsgc->get($psgc);
        }

        $place = $this->placeKey($warehouse->municipality, $warehouse->province);
        $candidates = $byPlace->get($place);
        if ($candidates instanceof Collection && $candidates->count() === 1) {
            return $candidates->first();
        }

        $municipalityToken = $this->normalizePlaceToken($warehouse->municipality);
        if ($municipalityToken === '') {
            return null;
        }

        $matches = $byPlace->flatten(1)->filter(function (LguDirectoryEntry $entry) use ($municipalityToken, $warehouse): bool {
            $nameToken = $this->normalizePlaceToken($entry->override_lgu_name ?: $entry->lgu_name);
            if (! $this->tokensOverlap($nameToken, $municipalityToken)) {
                return false;
            }

            $entryProvince = $this->normalizePlaceToken($this->provinceFromSourceSheet($entry->source_sheet));
            $warehouseProvince = $this->normalizePlaceToken($warehouse->province);

            return $entryProvince === '' || $warehouseProvince === '' || $entryProvince === $warehouseProvince;
        });

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function municipalityPsgcFromWarehouse(Warehouse $warehouse): string
    {
        if (preg_match('/^PH(\d{10})/i', (string) $warehouse->external_warehouse_id, $matches)) {
            return $this->normalizePsgc($matches[1]);
        }

        $barangay = preg_replace('/\D+/', '', (string) $warehouse->barangay_code) ?? '';
        if (strlen($barangay) >= 9) {
            return $this->normalizePsgc(substr($barangay, 0, 9).'0');
        }

        return '';
    }

    private function normalizePsgc(?string $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        return strlen($digits) >= 10 ? substr($digits, 0, 10) : $digits;
    }

    private function placeKey(?string $locality, ?string $province): string
    {
        return $this->normalizePlaceToken($province).'|'.$this->normalizePlaceToken($locality);
    }

    private function normalizePlaceToken(mixed $value): string
    {
        $text = strtolower(trim((string) $value));
        $text = preg_replace('/\b(city|municipality|lgu|province|of|the)\b/u', ' ', $text) ?? $text;
        $text = preg_replace('/[^a-z0-9]+/u', '', $text) ?? $text;

        return trim($text);
    }

    private function tokensOverlap(string $left, string $right): bool
    {
        if ($left === '' || $right === '') {
            return false;
        }

        return $left === $right
            || str_contains($left, $right)
            || str_contains($right, $left);
    }

    private function provinceFromSourceSheet(?string $sheet): ?string
    {
        return match (strtoupper((string) $sheet)) {
            'ADN' => 'Agusan Del Norte',
            'ADS' => 'Agusan Del Sur',
            'PDI' => 'Province of Dinagat Islands',
            'SDN' => 'Surigao Del Norte',
            'SDS' => 'Surigao Del Sur',
            default => $sheet,
        };
    }

    /**
     * @return list<string>
     */
    private function splitPeople(string $value): array
    {
        $value = trim($value);
        if ($value === '' || in_array(strtolower($value), ['n/a', 'na', '-'], true)) {
            return [];
        }

        return collect(preg_split('/[;\n]+/', $value) ?: [])
            ->map(fn ($part) => $this->cleanPersonName((string) $part))
            ->filter()
            ->values()
            ->all();
    }

    private function cleanPersonName(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($value === '' || in_array(strtolower($value), ['n/a', 'na', '-'], true)) {
            return '';
        }

        return $value;
    }

    private function cleanContact(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || in_array(strtolower($value), ['n/a', 'na', '-'], true)) {
            return null;
        }

        return $value;
    }

    private function cleanEmail(string $value): ?string
    {
        $value = trim($value);

        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    private function personKey(string $name): string
    {
        return Str::lower(preg_replace('/\s+/u', ' ', trim($name)) ?? '');
    }
}
