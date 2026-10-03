<?php

namespace App\Services;

use App\Models\LguDirectoryEntry;
use App\Models\OperationalLibraryValue;
use App\Models\RequestParty;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * SoTEx recipients: LGUs come only from the PSGC-linked LGU directory; DSWD offices,
 * NGAs, and NGOs live in the superadmin library type below, which never holds LGUs.
 */
class SotexRecipientLibrary
{
    public const LIBRARY_TYPE = 'sotex_recipient';

    public const PROVINCES = [
        'ADN' => 'Agusan del Norte',
        'ADS' => 'Agusan del Sur',
        'PDI' => 'Dinagat Islands',
        'SDN' => 'Surigao del Norte',
        'SDS' => 'Surigao del Sur',
    ];

    private const LEVELS = ['PLGU' => 1, 'CLGU' => 2, 'MLGU' => 3];

    /**
     * @return list<array{name: string, details: string, level: string}>
     */
    public function options(): array
    {
        $this->seedFromRequestParties();
        $lgus = $this->lguOptions();
        $taken = $lgus->map(fn (array $row): string => $this->normalized($row['name']))->flip();

        $others = OperationalLibraryValue::query()
            ->where('library_type', self::LIBRARY_TYPE)
            ->where('is_active', true)
            ->get(['value', 'metadata'])
            ->map(fn (OperationalLibraryValue $row): array => [
                'name' => trim((string) $row->value),
                'details' => trim((string) data_get($row->metadata, 'office')),
                'level' => '',
            ])
            ->filter(fn (array $row): bool => $row['name'] !== '' && ! $taken->has($this->normalized($row['name'])))
            ->unique(fn (array $row): string => $this->normalized($row['name']))
            ->sortBy(fn (array $row): string => mb_strtolower($row['name']), SORT_NATURAL)
            ->values();

        return $lgus->map(fn (array $row): array => collect($row)->only(['name', 'details', 'level'])->all())
            ->concat($others)
            ->values()
            ->all();
    }

    /**
     * Tells every open SoTEx planner to refetch its recipient list.
     */
    public function announceChanged(?string $name = null): void
    {
        $roles = Role::query()->whereIn('name', ['Super Admin', 'RROS', 'RROS AA', 'DRRS'])->pluck('name')->all();
        $plannerIds = $roles === [] ? collect() : User::role($roles)->pluck('id');
        if (Permission::query()->where('name', 'manage near expiry')->exists()) {
            $plannerIds = $plannerIds->merge(User::permission('manage near expiry')->pluck('id'));
        }

        app(RealtimePublisher::class)->usersChanged($plannerIds, 'sotex.recipients.changed', array_filter(['name' => $name]));
    }

    /**
     * LGU directory entry whose canonical label or bare place name matches, if any.
     */
    public function matchingLgu(string $name): ?string
    {
        $needle = $this->normalized($name);
        $bare = $this->normalizedPlace(preg_replace('/^(PLGU|CLGU|MLGU)\s*-\s*/i', '', $name) ?? $name);
        if ($needle === '') {
            return null;
        }

        foreach ($this->lguOptions() as $row) {
            if ($needle === $this->normalized($row['name']) || ($bare !== '' && $bare === $row['place'])) {
                return $row['name'];
            }
        }

        return null;
    }

    public function add(string $name, string $office = ''): OperationalLibraryValue
    {
        $existing = OperationalLibraryValue::query()
            ->where('library_type', self::LIBRARY_TYPE)
            ->get()
            ->first(fn (OperationalLibraryValue $row): bool => $this->normalized($row->value) === $this->normalized($name));
        if ($existing instanceof OperationalLibraryValue) {
            $updates = $existing->is_active ? [] : ['is_active' => true];
            if ($office !== '' && blank(data_get($existing->metadata, 'office'))) {
                $updates['metadata'] = [...($existing->metadata ?? []), 'office' => $office];
            }
            if ($updates !== []) {
                $existing->update($updates);
            }

            return $existing;
        }

        return OperationalLibraryValue::create([
            'library_type' => self::LIBRARY_TYPE,
            'value' => $name,
            'context' => 'all',
            'metadata' => array_filter(['office' => $office]),
            'is_active' => true,
        ]);
    }

    /**
     * @return Collection<int, array{name: string, details: string, level: string, place: string}>
     */
    private function lguOptions(): Collection
    {
        return LguDirectoryEntry::query()
            ->where('is_active', true)
            ->get(['source_sheet', 'lgu_name', 'override_lgu_name', 'lgu_level', 'congressional_district', 'override_congressional_district'])
            ->filter(fn (LguDirectoryEntry $entry): bool => isset(self::LEVELS[$entry->lgu_level]))
            ->map(function (LguDirectoryEntry $entry): array {
                $code = strtoupper(trim((string) $entry->source_sheet));
                $province = self::PROVINCES[$code] ?? '';
                $place = $this->placeLabel((string) ($entry->override_lgu_name ?: $entry->lgu_name));
                $district = $entry->lgu_level === 'PLGU'
                    ? ''
                    : trim((string) ($entry->override_congressional_district ?: $entry->congressional_district));
                $name = $entry->lgu_level === 'PLGU'
                    ? 'PLGU - '.($province ?: $place)
                    : $entry->lgu_level.' - '.$place.($code !== '' ? ', '.$code : '');

                return [
                    'name' => $name,
                    'details' => implode(' · ', array_filter([$province, $district])),
                    'level' => $entry->lgu_level,
                    'place' => $this->normalizedPlace($place),
                    'sort' => sprintf(
                        '%02d|%d|%s|%s',
                        array_search($code, array_keys(self::PROVINCES), true) === false ? 99 : array_search($code, array_keys(self::PROVINCES), true),
                        self::LEVELS[$entry->lgu_level],
                        $this->districtRank($district),
                        mb_strtolower($place),
                    ),
                ];
            })
            ->sortBy('sort')
            ->values();
    }

    /**
     * One-time copy of the offices and agencies that used to live only in the requesting-party sheet.
     */
    public function seedFromRequestParties(): void
    {
        if (OperationalLibraryValue::query()->where('library_type', self::LIBRARY_TYPE)->exists()) {
            return;
        }

        RequestParty::query()
            ->where('is_active', true)
            ->whereNull('lgu_directory_entry_id')
            ->where(fn ($query) => $query->whereNull('lgu_level')->orWhereNotIn('lgu_level', array_keys(self::LEVELS)))
            ->orderBy('requesting_party')
            ->get(['requesting_party', 'office_agency_details'])
            ->each(function (RequestParty $party): void {
                $name = trim((string) $party->requesting_party);
                if ($name === '' || $this->matchingLgu($name) !== null) {
                    return;
                }
                $office = trim((string) $party->office_agency_details);
                if (strcasecmp($office, $name) === 0 || strcasecmp($office, '(pls. edit to specify)') === 0) {
                    $office = '';
                }
                $this->add($name, $office);
            });
    }

    private function placeLabel(string $name): string
    {
        $name = trim(preg_replace('/\s*\([^)]*\)/u', '', $name) ?? $name);

        return $name === mb_strtoupper($name) ? ucwords(mb_strtolower($name), " \t") : $name;
    }

    private function districtRank(string $district): string
    {
        if ($district === '') {
            return '9';
        }
        if (stripos($district, 'lone') !== false) {
            return '0';
        }

        return preg_match('/(\d+)/', $district, $match) === 1 ? (string) min(8, (int) $match[1]) : '8';
    }

    private function normalized(?string $value): string
    {
        return OperationalLibraryValue::normalizeLibraryName($value);
    }

    private function normalizedPlace(?string $name): string
    {
        $name = strtoupper(trim(preg_replace('/\([^)]*\)/u', '', (string) $name) ?? ''));
        $name = preg_replace('/^(PROVINCE|CITY)\s+OF\s+/', '', $name) ?? $name;
        $name = preg_replace('/\s+CITY$/', '', $name) ?? $name;

        return Str::of($name)->replaceMatches('/[^A-Z0-9]+/', '')->toString();
    }
}
