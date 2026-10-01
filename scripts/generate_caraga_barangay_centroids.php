<?php

/**
 * Generate config/caraga_barangay_centroids.php from HDX COD-AB admin4 centers.
 *
 * Source: https://data.humdata.org/dataset/cod-ab-phl (CC BY-IGO)
 * Input CSV: storage/app/geo/phl_admin4.csv
 *
 * Regenerate:
 *   npx --yes xlsx-cli storage/app/geo/phl_admin_boundaries.xlsx --sheet phl_admin4 | Set-Content -Encoding utf8 storage/app/geo/phl_admin4.csv
 *   php scripts/generate_caraga_barangay_centroids.php
 */

$root = dirname(__DIR__);
$csvPath = $root.'/storage/app/geo/phl_admin4.csv';
$outPath = $root.'/config/caraga_barangay_centroids.php';

if (! is_file($csvPath)) {
    fwrite(STDERR, "Missing {$csvPath}\n");
    exit(1);
}

$handle = fopen($csvPath, 'r');
if ($handle === false) {
    fwrite(STDERR, "Unable to open CSV\n");
    exit(1);
}

$header = fgetcsv($handle, null, ',', '"', '\\');
if (! is_array($header)) {
    fwrite(STDERR, "Empty CSV\n");
    exit(1);
}

// Skip sheet-name line if present.
if (count($header) === 1 && strcasecmp(trim((string) $header[0]), 'phl_admin4') === 0) {
    $header = fgetcsv($handle, null, ',', '"', '\\');
}

$index = array_flip($header);
foreach (['adm4_pcode', 'adm1_pcode', 'center_lat', 'center_lon'] as $required) {
    if (! isset($index[$required])) {
        fwrite(STDERR, "Missing column {$required}\n");
        exit(1);
    }
}

$centroids = [];
while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
    if (! isset($row[$index['adm1_pcode']])) {
        continue;
    }

    if (strtoupper(trim((string) $row[$index['adm1_pcode']])) !== 'PH16') {
        continue;
    }

    $pcode = strtoupper(trim((string) $row[$index['adm4_pcode']]));
    if (! preg_match('/^PH(\d{10})$/', $pcode, $matches)) {
        continue;
    }

    $lat = $row[$index['center_lat']] ?? null;
    $lng = $row[$index['center_lon']] ?? null;
    if ($lat === null || $lng === null || $lat === '' || $lng === '') {
        continue;
    }

    $centroids[$matches[1]] = [round((float) $lat, 6), round((float) $lng, 6)];
}

fclose($handle);
ksort($centroids);

$fmt = static function (float $value): string {
    $formatted = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

    return $formatted === '' ? '0' : $formatted;
};

$lines = [
    '<?php',
    '',
    '/**',
    ' * Barangay center coordinates for Caraga (decimal degrees).',
    ' *',
    ' * Generated from HDX/OCHA Philippines COD-AB admin4 centers',
    ' * (phl_admin_boundaries.xlsx / phl_admin4.csv, CC BY-IGO).',
    ' * Keyed by 10-digit PSGC.',
    ' *',
    ' * Regenerate: php scripts/generate_caraga_barangay_centroids.php',
    ' *',
    ' * @return array<string, array{0: float, 1: float}>',
    ' */',
    'return [',
];

foreach ($centroids as $code => [$lat, $lng]) {
    $lines[] = sprintf("    '%s' => [%s, %s],", $code, $fmt($lat), $fmt($lng));
}

$lines[] = '];';
$lines[] = '';

file_put_contents($outPath, implode(PHP_EOL, $lines));

echo 'Wrote '.count($centroids)." Caraga barangay centroids\n";
foreach (['1606727001', '1606727006', '1600211001', '1600301001'] as $code) {
    if (isset($centroids[$code])) {
        echo "{$code}: {$centroids[$code][0]}, {$centroids[$code][1]}\n";
    } else {
        echo "{$code}: MISSING\n";
    }
}
