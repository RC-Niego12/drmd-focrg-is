<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseLibraryValue extends Model
{
    public const TYPES = [
        'distribution_network' => 'Distribution Network',
        'warehouse_type' => 'Warehouse Type',
        'warehouse_category' => 'Warehouse Category',
        'partnership' => 'Partnership',
        'ownership' => 'Ownership',
    ];

    protected $fillable = ['library_type', 'value', 'applicability'];

    public static function populateDefaults(): void
    {
        $defaults = [
            ['distribution_network', 'Last Mile', 'prepositioning'],
            ['distribution_network', 'Spokes', 'other'],
            ['warehouse_type', 'Prepositioning Area', 'prepositioning'],
            ['warehouse_type', 'Regional Warehouse', 'other'],
            ['warehouse_type', 'Satellite Warehouse', 'other'],
            ['warehouse_category', 'KC', 'prepositioning'],
            ['warehouse_category', 'Non-KC', 'prepositioning'],
            ['warehouse_category', 'Owned', 'other'],
            ['warehouse_category', 'Rented', 'other'],
            ['ownership', 'LGU', 'prepositioning'],
            ['ownership', 'NGA', 'prepositioning'],
            ['ownership', 'Owned', 'other'],
            ['ownership', 'Rented', 'other'],
            ['partnership', 'City', 'prepositioning'],
            ['partnership', 'Municipal', 'prepositioning'],
            ['partnership', 'Provincial', 'prepositioning'],
            ['partnership', 'PPA', 'prepositioning'],
            ['partnership', 'DPWH', 'prepositioning'],
            ['partnership', 'Owned', 'other'],
            ['partnership', 'Rented', 'other'],
        ];

        foreach ($defaults as [$type, $value, $scope]) {
            self::firstOrCreate(['library_type' => $type, 'value' => $value, 'applicability' => $scope]);
        }

        $columns = ['distribution_network', 'warehouse_type', 'category', 'partnership', 'ownership'];
        Warehouse::query()->get($columns)->each(function (Warehouse $warehouse) use ($columns): void {
            $prepositioning = str_contains(preg_replace('/[^a-z0-9]+/', '', strtolower((string) $warehouse->warehouse_type)), 'preposition');
            foreach ($columns as $column) {
                $value = trim((string) $warehouse->{$column});
                if ($value === '') continue;
                $type = $column === 'category' ? 'warehouse_category' : $column;
                $ownedOrRented = in_array(strtolower($value), ['owned', 'rented'], true);
                if (in_array($column, ['category', 'ownership', 'partnership'], true) && (($prepositioning && $ownedOrRented) || (! $prepositioning && ! $ownedOrRented))) continue;
                self::firstOrCreate(['library_type' => $type, 'value' => $value, 'applicability' => $prepositioning ? 'prepositioning' : 'other']);
            }
        });
    }

    public static function values(string $type, string $scope): array
    {
        return self::query()->where('library_type', $type)->whereIn('applicability', ['all', $scope])->orderBy('value')->pluck('value')->unique()->values()->all();
    }
}
