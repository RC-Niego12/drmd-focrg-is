<?php

namespace App\Support;

final class AffectedAreaList
{
    /**
     * @param  array<int, mixed>  $areas
     */
    public static function format(array $areas): string
    {
        $names = collect($areas)
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->map(fn (string $value): string => trim((string) preg_replace('/^(?:brgy\.?|barangay)\s+/i', '', $value)))
            ->filter()
            ->unique()
            ->values()
            ->map(fn (string $value): string => 'Brgy. '.$value);

        return match ($names->count()) {
            0 => '',
            1 => $names->first(),
            2 => $names->implode(' and '),
            default => $names->slice(0, -1)->implode(', ').', and '.$names->last(),
        };
    }
}
