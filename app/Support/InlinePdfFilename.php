<?php

namespace App\Support;

use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Build safe inline/attachment PDF filenames and Content-Disposition values.
 */
class InlinePdfFilename
{
    /**
     * Sanitize a PDF filename: strip path junk, replace reserved characters, ensure .pdf.
     */
    public static function sanitize(string $name): string
    {
        // Replace reserved path/filename characters first so basename cannot drop
        // official ID segments that happen to contain "/" (e.g. rare refs).
        $cleaned = str_replace(["\r", "\n"], '', trim($name));
        $cleaned = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $cleaned);
        $cleaned = preg_replace('/\s+/u', ' ', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/-+/', '-', $cleaned) ?? $cleaned;
        $cleaned = trim($cleaned, ' .-_');

        if ($cleaned === '' || $cleaned === '.' || $cleaned === '..') {
            return 'document.pdf';
        }

        if (str_ends_with(Str::lower($cleaned), '.pdf')) {
            $stem = trim(substr($cleaned, 0, -4), ' .-_');

            return ($stem === '' ? 'document' : $stem).'.pdf';
        }

        return "{$cleaned}.pdf";
    }

    /**
     * First non-blank candidate, sanitized as a .pdf filename.
     */
    public static function fromCandidates(string|int|null ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            $value = trim((string) ($candidate ?? ''));
            if ($value !== '') {
                return self::sanitize($value);
            }
        }

        return 'document.pdf';
    }

    /**
     * Content-Disposition with ASCII filename + UTF-8 filename*.
     */
    public static function disposition(string $filename, string $disposition = 'inline'): string
    {
        $safe = self::sanitize($filename);
        $fallback = preg_replace('/[^\x20-\x7E]/', '_', $safe) ?: 'document.pdf';
        $fallback = str_replace('%', '', $fallback);

        return HeaderUtils::makeDisposition($disposition, $safe, $fallback);
    }
}
