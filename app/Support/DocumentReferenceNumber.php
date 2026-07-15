<?php

namespace App\Support;

class DocumentReferenceNumber
{
    public static function compose(string $prefix, string $year, string $month, string $specified): string
    {
        return implode('-', [
            trim($prefix, " \t\n\r\0\x0B-"),
            trim($year),
            trim($month),
            trim($specified, " \t\n\r\0\x0B-"),
        ]);
    }
}
