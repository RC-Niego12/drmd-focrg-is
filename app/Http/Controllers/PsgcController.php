<?php

namespace App\Http\Controllers;

use App\Models\PsgcAddress;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PsgcController extends Controller
{
    public function regions(): JsonResponse
    {
        return response()->json($this->localOrRemote('region', null, 'regions'));
    }

    public function provinces(string $regionCode): JsonResponse
    {
        return response()->json($this->localOrRemote('province', $regionCode, "regions/{$regionCode}/provinces"));
    }

    public function citiesMunicipalities(string $provinceCode): JsonResponse
    {
        return response()->json($this->localOrRemote('city_municipality', $provinceCode, "provinces/{$provinceCode}/cities-municipalities"));
    }

    public function districts(string $provinceCode): JsonResponse
    {
        return response()->json($this->localOrRemote('district', $provinceCode, ''));
    }

    public function districtCitiesMunicipalities(string $districtCode): JsonResponse
    {
        return response()->json($this->localOrRemote('city_municipality', null, '', $districtCode));
    }

    public function barangays(string $cityMunicipalityCode): JsonResponse
    {
        return response()->json($this->localOrRemote('barangay', $cityMunicipalityCode, "cities-municipalities/{$cityMunicipalityCode}/barangays"));
    }

    private function localOrRemote(string $level, ?string $parentCode, string $remotePath, ?string $districtCode = null): array
    {
        $rows = PsgcAddress::query()
            ->where('level', $level)
            ->where('is_active', true)
            ->when($parentCode, fn ($query) => $query->where('parent_code', $parentCode))
            ->when(! $parentCode && ! $districtCode, fn ($query) => $query->whereNull('parent_code'))
            ->when($districtCode, fn ($query) => $query->where('district_code', $districtCode))
            ->orderBy('name')
            ->get()
            ->map(fn (PsgcAddress $address): array => [
                'name' => $address->name,
                'short_name' => $address->short_name,
                'code' => $address->code,
                'type' => $address->type,
                'district' => $address->district,
                'district_code' => $address->district_code,
                'zip_code' => $address->zip_code,
            ])
            ->values()
            ->all();

        if (count($rows) > 0) {
            return $rows;
        }

        return $remotePath !== '' ? $this->fetch($remotePath) : [];
    }

    private function fetch(string $path): array
    {
        return Cache::remember("psgc.{$path}", now()->addDays(30), function () use ($path): array {
            $baseUrl = rtrim((string) config('services.psgc.base_url', 'https://psgc.cloud/api'), '/');

            try {
                $response = Http::timeout(20)->acceptJson()->get("{$baseUrl}/{$path}");
            } catch (ConnectionException $exception) {
                throw new RuntimeException('Could not connect to the PSGC address reference service.', previous: $exception);
            }

            if (! $response->successful()) {
                throw new RuntimeException("PSGC address reference request failed with HTTP {$response->status()}.");
            }

            return collect($response->json() ?? [])
                ->map(fn (array $row): array => [
                    'name' => trim((string) ($row['name'] ?? '')),
                    'short_name' => null,
                    'code' => (string) ($row['code'] ?? ''),
                    'type' => $row['type'] ?? null,
                    'district' => $row['district'] ?? null,
                    'zip_code' => $row['zip_code'] ?? null,
                ])
                ->filter(fn (array $row): bool => $row['name'] !== '' && $row['code'] !== '')
                ->values()
                ->all();
        });
    }
}
