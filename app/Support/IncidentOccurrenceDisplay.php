<?php

namespace App\Support;

final class IncidentOccurrenceDisplay
{
    /**
     * Format occurrence dates:
     * - same day → mm/dd/yyyy
     * - same month → mm/dd-dd/yyyy
     * - same year, different months → mm/dd-mm/dd/yyyy
     * - different years → mm/dd/yyyy-mm/dd/yyyy
     */
    public static function format(?string $start, ?string $end = null): string
    {
        $from = self::parts($start);
        if ($from === null) {
            return '';
        }
        $to = self::parts($end ?: $start) ?? $from;

        if ($from['year'] === $to['year'] && $from['month'] === $to['month'] && $from['day'] === $to['day']) {
            return "{$from['month']}/{$from['day']}/{$from['year']}";
        }
        if ($from['year'] === $to['year'] && $from['month'] === $to['month']) {
            return "{$from['month']}/{$from['day']}-{$to['day']}/{$from['year']}";
        }
        if ($from['year'] === $to['year']) {
            return "{$from['month']}/{$from['day']}-{$to['month']}/{$to['day']}/{$from['year']}";
        }

        return "{$from['month']}/{$from['day']}/{$from['year']}-{$to['month']}/{$to['day']}/{$to['year']}";
    }

    /**
     * @return array{year: string, month: string, day: string}|null
     */
    private static function parts(?string $isoDate): ?array
    {
        if (! is_string($isoDate) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $isoDate, $match)) {
            return null;
        }

        return [
            'year' => $match[1],
            'month' => $match[2],
            'day' => $match[3],
        ];
    }
}
