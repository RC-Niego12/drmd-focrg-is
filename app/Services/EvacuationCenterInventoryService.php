<?php

namespace App\Services;

use App\Models\PsgcAddress;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EvacuationCenterInventoryService
{
    /**
     * @return array{source:?string,generated_at:?string,count:int,centers:list<array<string,mixed>>}
     */
    public function inventory(): array
    {
        $path = database_path('data/caraga_evacuation_centers.json');
        if (! is_file($path)) {
            return [
                'source' => null,
                'generated_at' => null,
                'count' => 0,
                'centers' => [],
                'photo_sources' => [],
                'photos_merged_at' => null,
            ];
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload)) {
            return [
                'source' => null,
                'generated_at' => null,
                'count' => 0,
                'centers' => [],
                'photo_sources' => [],
                'photos_merged_at' => null,
            ];
        }

        $centers = array_values(array_filter(
            is_array($payload['centers'] ?? null) ? $payload['centers'] : [],
            function ($row): bool {
                if (! is_array($row)) {
                    return false;
                }

                // Defense in depth if an older snapshot still includes CLOSED rows.
                return strcasecmp((string) ($row['tc_crising'] ?? ''), 'CLOSED') !== 0;
            }
        ));

        return [
            'source' => $payload['source'] ?? null,
            'generated_at' => $payload['generated_at'] ?? null,
            'count' => count($centers),
            'centers' => $centers,
            'photo_sources' => is_array($payload['photo_sources'] ?? null) ? $payload['photo_sources'] : [],
            'photos_merged_at' => $payload['photos_merged_at'] ?? null,
        ];
    }

    /**
     * @return Collection<int, array<string,mixed>>
     */
    public function centersForMunicipality(?string $municipality): Collection
    {
        $needle = $this->normalizePlace($municipality);
        if ($needle === '') {
            return collect();
        }

        return collect($this->inventory()['centers'])
            ->filter(fn (array $row) => $this->normalizePlace($row['municipality'] ?? null) === $needle)
            ->values();
    }

    /**
     * @return Collection<int, array<string,mixed>>
     */
    public function centersForUser(User $user): Collection
    {
        $directory = app(LguWarehousePersonnelSyncService::class)->directoryForUser($user);
        $names = collect([
            $directory?->override_lgu_name,
            $directory?->lgu_name,
            $user->lgu_name,
        ])->filter()->map(fn ($name) => $this->normalizePlace($name))->unique()->values();

        if ($directory?->psgc_code) {
            $psgcName = PsgcAddress::query()
                ->where('code', $directory->psgc_code)
                ->value('name');
            if (filled($psgcName)) {
                $names->push($this->normalizePlace($psgcName));
            }
        }

        $names = $names->filter()->unique()->values();
        if ($names->isEmpty()) {
            return collect();
        }

        return collect($this->inventory()['centers'])
            ->filter(fn (array $row) => $names->contains($this->normalizePlace($row['municipality'] ?? null)))
            ->values();
    }

    /**
     * @param  Collection<int, array<string,mixed>>  $centers
     * @return array{total:int,operational:int,family_capacity:int,individual_capacity:int,barangays:int,types:int,with_photos:int}
     */
    public function metrics(Collection $centers): array
    {
        return [
            'total' => $centers->count(),
            'operational' => $centers->filter(
                fn (array $row) => Str::contains(Str::lower((string) ($row['building_status'] ?? '')), 'operational')
            )->count(),
            'family_capacity' => (int) $centers->sum(fn (array $row) => (int) ($row['family_capacity'] ?? 0)),
            'individual_capacity' => (int) $centers->sum(fn (array $row) => (int) ($row['individual_capacity'] ?? 0)),
            'barangays' => $centers->pluck('barangay')->filter()->unique()->count(),
            'types' => $centers->pluck('ec_type')->filter()->unique()->count(),
            'with_photos' => $centers->filter(
                fn (array $row) => ($row['photo_source'] ?? null) === 'lgu_geotag_sheet'
                    || filled($row['photo_url'] ?? null)
                    || (is_array($row['photo_urls'] ?? null) && count($row['photo_urls']) > 0)
            )->count(),
        ];
    }

    /**
     * @param  Collection<int, array<string,mixed>>  $centers
     * @return Collection<int, array<string,mixed>>
     */
    public function sortCenters(Collection $centers): Collection
    {
        return $centers
            ->sortBy([
                fn (array $row) => Str::lower((string) ($row['municipality'] ?? '')),
                fn (array $row) => Str::lower((string) ($row['barangay'] ?? '')),
                fn (array $row) => Str::lower((string) ($row['name'] ?? '')),
            ], SORT_NATURAL)
            ->values();
    }

    /**
     * @return list<array{code:string,name:string,province:string,title:string,looker_url:string,open_url:string,sheet_municipality:string,geotag_sheet_url:string,count:int,photo_count:int}>
     */
    public function featuredDashboards(?Collection $allCenters = null): array
    {
        $allCenters ??= collect($this->inventory()['centers']);
        $config = config('evac_center_dashboards', []);
        $photoSheets = collect(config('evac_center_photo_sheets', []))
            ->keyBy(fn (array $sheet) => $this->normalizePlace($sheet['sheet_municipality'] ?? $sheet['name'] ?? ''));

        return collect($config)
            ->map(function (array $dashboard, string $code) use ($allCenters, $photoSheets): array {
                $muni = (string) ($dashboard['sheet_municipality'] ?? $dashboard['name'] ?? '');
                $lookerUrl = (string) ($dashboard['looker_url'] ?? '');
                $openUrl = (string) ($dashboard['open_url'] ?? '');
                if ($openUrl === '' && $lookerUrl !== '') {
                    $openUrl = str_replace('/embed/reporting/', '/reporting/', $lookerUrl);
                }
                $rows = $allCenters->filter(
                    fn (array $row) => $this->normalizePlace($row['municipality'] ?? null) === $this->normalizePlace($muni)
                );
                $photoSheet = $photoSheets->get($this->normalizePlace($muni), []);

                return [
                    'code' => $code,
                    'name' => (string) ($dashboard['name'] ?? $muni),
                    'province' => (string) ($dashboard['province'] ?? ''),
                    'title' => (string) ($dashboard['title'] ?? ('Geotagged ECs — '.$muni)),
                    'looker_url' => $lookerUrl,
                    'open_url' => $openUrl,
                    'sheet_municipality' => $muni,
                    'geotag_sheet_url' => (string) ($photoSheet['source_url'] ?? ''),
                    'count' => $rows->count(),
                    'photo_count' => $rows->filter(
                        fn (array $row) => ($row['photo_source'] ?? null) === 'lgu_geotag_sheet'
                            || filled($row['photo_url'] ?? null)
                    )->count(),
                ];
            })
            ->values()
            ->all();
    }

    public function featuredDashboardForUser(User $user): ?array
    {
        $directory = app(LguWarehousePersonnelSyncService::class)->directoryForUser($user);
        $psgc = (string) ($directory?->psgc_code ?: $user->lgu_psgc_code ?: '');
        $dashboards = config('evac_center_dashboards', []);

        if ($psgc !== '' && isset($dashboards[$psgc])) {
            return [
                'code' => $psgc,
                ...$dashboards[$psgc],
            ];
        }

        $names = collect([
            $directory?->override_lgu_name,
            $directory?->lgu_name,
            $user->lgu_name,
        ])->filter()->map(fn ($name) => $this->normalizePlace($name));

        foreach ($dashboards as $code => $dashboard) {
            $muni = $this->normalizePlace($dashboard['sheet_municipality'] ?? $dashboard['name'] ?? null);
            if ($muni !== '' && $names->contains($muni)) {
                return [
                    'code' => $code,
                    ...$dashboard,
                ];
            }
        }

        return null;
    }

    private function normalizePlace(?string $value): string
    {
        $value = Str::lower(trim((string) $value));
        $value = preg_replace('/^(plgu|clgu|mlgu|lgu)\s+/', '', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return $value;
    }
}
