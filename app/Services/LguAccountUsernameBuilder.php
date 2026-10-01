<?php

namespace App\Services;

use App\Models\LguDirectoryEntry;
use App\Models\PsgcAddress;
use Illuminate\Support\Str;

class LguAccountUsernameBuilder
{
    private const PROVINCE_ABBREVIATIONS = [
        '1600200000' => 'adn',
        '1600300000' => 'ads',
        '1608500000' => 'pdi',
        '1606700000' => 'sdn',
        '1606800000' => 'sds',
    ];

    private const SPECIAL_CITY_PROVINCE_CODES = [
        '1630400000' => '1600200000',
    ];

    public const ROLE_SUFFIXES = [
        'lce' => 'lce',
        'lswd_officer' => 'lswdo',
        'ldrrmo' => 'ldrrmo',
    ];

    public function baseUsernameForDirectory(LguDirectoryEntry $directory): ?string
    {
        if (! filled($directory->psgc_code)) {
            return $this->baseUsernameFromLabels(
                (string) ($directory->lgu_level ?: ''),
                (string) ($directory->override_lgu_name ?: $directory->lgu_name),
                (string) ($directory->source_sheet ?: ''),
            );
        }

        $address = PsgcAddress::query()->where('code', $directory->psgc_code)->first();
        if ($address) {
            return $this->baseUsernameForPsgc($address);
        }

        return $this->baseUsernameFromLabels(
            (string) ($directory->lgu_level ?: ''),
            (string) ($directory->override_lgu_name ?: $directory->lgu_name),
            (string) ($directory->source_sheet ?: ''),
        );
    }

    public function roleUsername(string $baseUsername, string $directoryRole): string
    {
        $suffix = self::ROLE_SUFFIXES[$directoryRole] ?? Str::of($directoryRole)->lower()->replace('_', '')->toString();

        return rtrim($baseUsername, '-').'-'.$suffix;
    }

    public function baseUsernameForPsgc(PsgcAddress $address): string
    {
        $provinceCode = $this->provinceCodeFor($address);
        $province = self::PROVINCE_ABBREVIATIONS[(string) $provinceCode]
            ?? Str::of((string) $provinceCode)->substr(-4)->lower()->toString();

        if ($address->level === 'province') {
            return 'plgu-'.$province;
        }

        $name = Str::of($this->localGovernmentName($address))
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->toString();

        $prefix = str_contains(strtolower((string) $address->type), 'city') ? 'clgu' : 'mlgu';

        return "{$prefix}-{$name}-{$province}";
    }

    private function baseUsernameFromLabels(string $level, string $lguName, string $sourceSheet): ?string
    {
        $province = Str::of($sourceSheet)->lower()->toString();
        if ($province === '') {
            return null;
        }

        $normalizedLevel = strtoupper(trim($level));
        if ($normalizedLevel === 'PLGU') {
            return 'plgu-'.$province;
        }

        $name = Str::of($lguName)
            ->lower()
            ->replaceMatches('/,\s*.+$/', '')
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->toString();

        if ($name === '') {
            return null;
        }

        $prefix = $normalizedLevel === 'CLGU' ? 'clgu' : 'mlgu';

        return "{$prefix}-{$name}-{$province}";
    }

    private function provinceCodeFor(PsgcAddress $address): string
    {
        if ($address->level === 'province') {
            return (string) $address->code;
        }

        return self::SPECIAL_CITY_PROVINCE_CODES[(string) $address->code]
            ?? (string) $address->parent_code;
    }

    private function localGovernmentName(PsgcAddress $address): string
    {
        $name = trim((string) $address->name);

        if (preg_match('/^City of\s+(.+)$/i', $name, $matches)) {
            return trim($matches[1]).' City';
        }

        if (preg_match('/^Municipality of\s+(.+)$/i', $name, $matches)) {
            return trim($matches[1]);
        }

        return $name;
    }
}
