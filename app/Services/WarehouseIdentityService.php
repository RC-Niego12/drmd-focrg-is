<?php

namespace App\Services;

use App\Models\PsgcAddress;
use App\Models\Warehouse;
use Illuminate\Support\Str;

class WarehouseIdentityService
{
    public function generate(array $data, ?Warehouse $ignoreWarehouse = null): array
    {
        $municipality = trim((string) ($data['municipality'] ?? ''));
        $province = trim((string) ($data['province'] ?? ''));
        $baseCode = $this->locationCode($municipality, $province);
        $suffix = $this->suffix($data);
        $warehouseNumber = trim((string) ($data['warehouse_number'] ?? '')) ?: $this->nextWarehouseNumber($baseCode, $data, $ignoreWarehouse);

        return [
            'warehouse_number' => $warehouseNumber,
            'external_warehouse_id' => $baseCode ? 'PH'.$baseCode.'-'.$this->idNumber($warehouseNumber).$suffix : '',
            'name' => $this->warehouseName($municipality, $warehouseNumber, $data),
            'base_code' => $baseCode,
            'suffix' => $suffix,
        ];
    }

    public function fillMissing(array $data, ?Warehouse $ignoreWarehouse = null): array
    {
        $generated = $this->generate($data, $ignoreWarehouse);

        foreach (['warehouse_number', 'external_warehouse_id', 'name'] as $field) {
            if (blank($data[$field] ?? null) && filled($generated[$field] ?? null)) {
                $data[$field] = $generated[$field];
            }
        }

        return $data;
    }

    private function locationCode(string $municipality, string $province): string
    {
        if ($municipality === '') {
            return '';
        }

        $provinceCode = PsgcAddress::query()
            ->where('level', 'province')
            ->where('is_active', true)
            ->whereRaw('LOWER(name) = ?', [strtolower($province)])
            ->value('code');

        $query = PsgcAddress::query()
            ->where('level', 'city_municipality')
            ->where('is_active', true);

        if ($provinceCode) {
            $query->where('parent_code', $provinceCode);
        }

        $city = $query->get(['code', 'name'])
            ->first(fn (PsgcAddress $row): bool => $this->sameLocalityName($row->name, $municipality));

        return (string) ($city?->code ?? '');
    }

    private function suffix(array $data): string
    {
        $warehouseType = $this->normalize((string) ($data['warehouse_type'] ?? ''));
        $category = $this->normalize((string) ($data['category'] ?? ''));
        $ownership = $this->normalize((string) ($data['ownership'] ?? ''));
        $partnership = $this->normalize((string) ($data['partnership'] ?? ''));

        $typeLetter = match (true) {
            str_contains($warehouseType, 'preposition') => 'L',
            str_contains($warehouseType, 'regional') || str_contains($warehouseType, 'satellite') => 'S',
            default => '',
        };

        if ($typeLetter === '') {
            return '';
        }

        $categoryLetter = match (true) {
            str_contains($category, 'partner') || str_contains($partnership, 'ppa') || str_contains($partnership, 'dpwh') => 'P',
            $typeLetter === 'L' && str_contains($category, 'nonkc') => 'N',
            $typeLetter === 'L' && $category === 'kc' => 'K',
            $typeLetter === 'S' && (str_contains($category, 'rented') || str_contains($ownership, 'rented')) => 'R',
            $typeLetter === 'S' && (str_contains($category, 'owned') || str_contains($ownership, 'owned')) => 'O',
            default => '',
        };

        return $typeLetter.$categoryLetter;
    }

    private function nextWarehouseNumber(string $baseCode, array $data, ?Warehouse $ignoreWarehouse): string
    {
        if ($this->normalize((string) ($data['partnership'] ?? '')) === 'ppa') {
            return '091';
        }

        if ($baseCode === '') {
            return '01';
        }

        $query = Warehouse::query()
            ->withTrashed()
            ->where('external_warehouse_id', 'like', 'PH'.$baseCode.'-%');

        if ($ignoreWarehouse) {
            $query->whereKeyNot($ignoreWarehouse->getKey());
        }

        $max = $query->get(['external_warehouse_id', 'warehouse_number'])
            ->map(function (Warehouse $warehouse): int {
                preg_match('/-(\d+)[A-Z]{2}$/', (string) $warehouse->external_warehouse_id, $match);
                $value = (int) ($warehouse->warehouse_number ?: ($match[1] ?? 0));

                return $value >= 90 ? 0 : $value;
            })
            ->max() ?? 0;

        return str_pad((string) ($max + 1), 2, '0', STR_PAD_LEFT);
    }

    private function idNumber(string $warehouseNumber): string
    {
        if (strlen($warehouseNumber) === 3 && str_starts_with($warehouseNumber, '0')) {
            return substr($warehouseNumber, 1);
        }

        return str_pad($warehouseNumber, 2, '0', STR_PAD_LEFT);
    }

    private function warehouseName(string $municipality, string $warehouseNumber, array $data): string
    {
        if ($this->normalize((string) ($data['distribution_network'] ?? '')) === 'spokes') {
            $barangay = trim((string) ($data['barangay_name'] ?? ''));

            return $barangay !== ''
                ? trim($municipality).' - '.$barangay
                : trim($municipality);
        }

        if ($this->normalize((string) ($data['partnership'] ?? '')) === 'ppa') {
            return 'PMO '.Str::of($municipality)->replaceMatches('/^City of\s+/i', '')->trim();
        }

        return trim($municipality).$this->idNumber($warehouseNumber);
    }

    private function firstLetter(mixed $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? '' : strtoupper(substr($value, 0, 1));
    }

    private function normalize(string $value): string
    {
        return (string) Str::of($value)->lower()->replaceMatches('/[^a-z0-9]+/', '')->trim();
    }

    private function sameLocalityName(string $left, string $right): bool
    {
        $left = $this->normalize($left);
        $right = $this->normalize($right);

        if ($left === $right) {
            return true;
        }

        $aliases = fn (string $value): array => array_unique([
            $value,
            preg_replace('/^cityof/', '', $value),
            preg_replace('/city$/', '', $value),
        ]);

        return count(array_intersect($aliases($left), $aliases($right))) > 0;
    }
}
