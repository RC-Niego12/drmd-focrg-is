<?php

namespace App\Support;

use App\Models\FniLibraryItem;
use App\Models\RequestItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Summarizes requested line items as brief food / non-food prose for letters.
 *
 * Family Food Packs fold into food. All other FNI taxonomy buckets fold into
 * non-food. Output is only: "food items", "non-food items", or
 * "food and non-food items".
 */
final class RequestedGoodsTypeSummary
{
    private const SIDE_FOOD = 'food';

    private const SIDE_NON_FOOD = 'non-food';

    /**
     * @param  iterable<int, RequestItem|object|array>  $items
     */
    public static function summarize(iterable $items, string $fallback = 'food and non-food items'): string
    {
        $rows = Collection::make($items)
            ->filter(function ($item): bool {
                $quantity = (float) (
                    data_get($item, 'approved_quantity')
                    ?: data_get($item, 'requested_quantity')
                    ?: 0
                );

                return $quantity > 0 && filled(data_get($item, 'item_name'));
            })
            ->values();

        if ($rows->isEmpty()) {
            return $fallback;
        }

        $libraryById = self::libraryById($rows);
        $libraryByName = self::libraryByName($rows, $libraryById);

        $sides = $rows
            ->map(fn ($item): ?string => self::resolveSide($item, $libraryById, $libraryByName))
            ->filter()
            ->unique()
            ->values();

        if ($sides->isEmpty()) {
            return $fallback;
        }

        $hasFood = $sides->contains(self::SIDE_FOOD);
        $hasNonFood = $sides->contains(self::SIDE_NON_FOOD);

        if ($hasFood && $hasNonFood) {
            return 'food and non-food items';
        }

        if ($hasFood) {
            return 'food items';
        }

        if ($hasNonFood) {
            return 'non-food items';
        }

        return $fallback;
    }

    /**
     * @param  list<string>  $parts
     */
    public static function joinEnglish(array $parts): string
    {
        $parts = array_values(array_filter(array_map(
            static fn ($part): string => trim((string) $part),
            $parts
        ), static fn (string $part): bool => $part !== ''));

        return match (count($parts)) {
            0 => '',
            1 => $parts[0],
            2 => $parts[0].' and '.$parts[1],
            default => implode(', ', array_slice($parts, 0, -1)).', and '.$parts[array_key_last($parts)],
        };
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return Collection<int, FniLibraryItem>
     */
    private static function libraryById(Collection $rows): Collection
    {
        $ids = $rows
            ->map(fn ($item) => (int) (data_get($item, 'fni_library_item_id') ?: data_get($item, 'fniLibraryItem.id') ?: 0))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return collect();
        }

        return FniLibraryItem::query()
            ->whereIn('id', $ids)
            ->get(['id', 'item_category', 'item_name', 'brand_description'])
            ->keyBy('id');
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @param  Collection<int, FniLibraryItem>  $libraryById
     * @return Collection<string, FniLibraryItem>
     */
    private static function libraryByName(Collection $rows, Collection $libraryById): Collection
    {
        $names = $rows
            ->map(fn ($item): string => self::normalizedItemKey((string) data_get($item, 'item_name')))
            ->filter()
            ->unique()
            ->values();

        if ($names->isEmpty()) {
            return collect();
        }

        $known = $libraryById
            ->mapWithKeys(fn (FniLibraryItem $item): array => [
                self::normalizedItemKey(trim($item->item_name.($item->brand_description ? ' - '.$item->brand_description : ''))) => $item,
                self::normalizedItemKey((string) $item->item_name) => $item,
            ]);

        $missing = $names->reject(fn (string $key): bool => $known->has($key));
        if ($missing->isEmpty()) {
            return $known;
        }

        $candidates = FniLibraryItem::query()
            ->get(['id', 'item_category', 'item_name', 'brand_description']);

        foreach ($candidates as $candidate) {
            $fullKey = self::normalizedItemKey(trim($candidate->item_name.($candidate->brand_description ? ' - '.$candidate->brand_description : '')));
            $nameKey = self::normalizedItemKey((string) $candidate->item_name);
            if ($missing->contains($fullKey) || $missing->contains($nameKey)) {
                $known[$fullKey] = $candidate;
                $known[$nameKey] = $candidate;
            }
        }

        return $known;
    }

    /**
     * @param  Collection<int, FniLibraryItem>  $libraryById
     * @param  Collection<string, FniLibraryItem>  $libraryByName
     */
    private static function resolveSide(mixed $item, Collection $libraryById, Collection $libraryByName): ?string
    {
        $linked = data_get($item, 'fniLibraryItem.item_category')
            ?: $libraryById->get((int) data_get($item, 'fni_library_item_id'))?->item_category;
        if (filled($linked)) {
            return self::sideFromCategory((string) $linked, (string) data_get($item, 'item_name'));
        }

        $inventoryCategory = data_get($item, 'inventoryItem.category');
        if (filled($inventoryCategory)) {
            return self::sideFromCategory((string) $inventoryCategory, (string) data_get($item, 'item_name'));
        }

        $name = trim((string) data_get($item, 'item_name'));
        $matched = $libraryByName->get(self::normalizedItemKey($name));
        if ($matched) {
            return self::sideFromCategory((string) $matched->item_category, $name);
        }

        return self::heuristicSide($name);
    }

    private static function sideFromCategory(string $category, string $itemName): string
    {
        $normalized = Str::lower(trim($category));
        $normalizedItem = Str::lower(trim($itemName));

        if (
            str_contains($normalized, 'family food pack')
            || str_contains($normalizedItem, 'family food pack')
            || in_array($normalized, ['food', 'food item', 'food items'], true)
        ) {
            return self::SIDE_FOOD;
        }

        return self::SIDE_NON_FOOD;
    }

    private static function heuristicSide(string $itemName): ?string
    {
        $normalized = Str::lower(trim($itemName));
        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, 'family food pack')) {
            return self::SIDE_FOOD;
        }

        if (
            str_contains($normalized, 'clothing kit')
            || str_contains($normalized, 'hygiene kit')
            || str_contains($normalized, 'kitchen kit')
            || str_contains($normalized, 'sleeping kit')
            || str_contains($normalized, 'filtration kit')
            || str_contains($normalized, 'laminated sack')
            || str_contains($normalized, 'malong')
            || str_contains($normalized, 'blanket')
            || str_contains($normalized, 'mosquito net')
        ) {
            return self::SIDE_NON_FOOD;
        }

        if (
            str_contains($normalized, 'water')
            || str_contains($normalized, 'rice')
            || str_contains($normalized, 'canned')
            || str_contains($normalized, 'coffee')
            || str_contains($normalized, 'sardines')
            || str_contains($normalized, 'noodles')
        ) {
            return self::SIDE_FOOD;
        }

        return null;
    }

    private static function normalizedItemKey(string $value): string
    {
        return Str::lower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }
}
