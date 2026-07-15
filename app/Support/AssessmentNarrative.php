<?php

namespace App\Support;

final class AssessmentNarrative
{
    public static function sanitize(?string $value): string
    {
        $lines = preg_split('/\R/u', trim((string) $value)) ?: [];
        $lines = array_values(array_filter($lines, function (string $line): bool {
            $normalized = trim($line);

            return preg_match('/^(Signature|Date)\s*:\s*_+\s*$/iu', $normalized) !== 1;
        }));

        return trim(implode("\n", $lines));
    }
}
