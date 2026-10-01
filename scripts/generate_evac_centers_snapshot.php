<?php

/**
 * Snapshot Caraga evacuation centers from the OCD/LGU inventory Google Sheet CSV.
 *
 * Source: https://docs.google.com/spreadsheets/d/1N2fVODuyCAH7ZJ47TsC8-qic-CU43UMbi7Utphkp1Xo
 * Input: storage/app/geo/evac_sheet.csv
 * Output: database/data/caraga_evacuation_centers.json
 */

$root = dirname(__DIR__);
$csvPath = $root.'/storage/app/geo/evac_sheet.csv';
$outPath = $root.'/database/data/caraga_evacuation_centers.json';

if (! is_file($csvPath)) {
    fwrite(STDERR, "Missing {$csvPath}\n");
    exit(1);
}

if (! is_dir(dirname($outPath))) {
    mkdir(dirname($outPath), 0775, true);
}

$handle = fopen($csvPath, 'r');
$headers = fgetcsv($handle, 0, ',', '"', '\\');
if (! is_array($headers)) {
    fwrite(STDERR, "Empty CSV\n");
    exit(1);
}

$index = array_flip($headers);
$required = ['Province', 'MuniCity', 'Barangay', 'Longitude', 'Latitude', 'Evacuation Center Name'];
foreach ($required as $column) {
    if (! isset($index[$column])) {
        fwrite(STDERR, "Missing column {$column}\n");
        exit(1);
    }
}

$get = static function (array $row, array $index, string $key): string {
    if (! isset($index[$key])) {
        return '';
    }

    return trim((string) ($row[$index[$key]] ?? ''));
};

$toFloat = static function (string $value): ?float {
    $value = str_replace([',', ' '], '', $value);
    if ($value === '' || ! is_numeric($value)) {
        return null;
    }

    return round((float) $value, 7);
};

$toInt = static function (string $value): ?int {
    $value = str_replace([',', ' '], '', $value);
    if ($value === '' || ! is_numeric($value)) {
        return null;
    }

    return (int) round((float) $value);
};

$centers = [];
$skippedClosed = 0;
while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
    $name = $get($row, $index, 'Evacuation Center Name');
    $municipality = $get($row, $index, 'MuniCity');
    if ($name === '' || $municipality === '') {
        continue;
    }

    // Exclude centers marked CLOSED under Typhoon Crising operations.
    $tcCrising = $get($row, $index, 'TC CRISING');
    if (strcasecmp($tcCrising, 'CLOSED') === 0) {
        $skippedClosed++;
        continue;
    }

    $lat = $toFloat($get($row, $index, 'Latitude'));
    $lng = $toFloat($get($row, $index, 'Longitude'));
    $photo = $get($row, $index, 'Link for Geotagged Photos');
    $preview = null;
    if ($lat !== null && $lng !== null) {
        $preview = sprintf(
            'https://staticmap.openstreetmap.de/staticmap.php?center=%s,%s&zoom=16&size=720x420&maptype=mapnik&markers=%s,%s,red-pushpin',
            $lat,
            $lng,
            $lat,
            $lng,
        );
    }

    $yesNo = static function (string $value): ?string {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (strcasecmp($value, 'Yes') === 0) {
            return 'Yes';
        }
        if (strcasecmp($value, 'No') === 0) {
            return 'No';
        }

        return $value;
    };

    $centers[] = [
        'id' => substr(sha1(strtolower($municipality.'|'.$name.'|'.$lat.'|'.$lng)), 0, 16),
        'region' => $get($row, $index, 'Region') ?: 'Caraga',
        'province' => $get($row, $index, 'Province'),
        'municipality' => $municipality,
        'barangay' => $get($row, $index, 'Barangay'),
        'lat' => $lat,
        'lng' => $lng,
        'name' => $name,
        'ec_type' => $get($row, $index, 'EC Type'),
        'other_ec_type' => $get($row, $index, 'Other EC Type'),
        'address' => $get($row, $index, 'Address'),
        'availability' => $get($row, $index, 'Availability Status'),
        'building_status' => $get($row, $index, 'Building Status'),
        'floor_area_sqm' => $toFloat($get($row, $index, 'Floor Area (Sq. Meter)')),
        'family_capacity' => $toInt($get($row, $index, 'Family Capacity')),
        'individual_capacity' => $toInt($get($row, $index, 'Individual Capacity')),
        'rooms' => $toInt($get($row, $index, 'No. of Rooms')),
        'camp_manager' => $get($row, $index, 'Camp Manager'),
        'inventory_date' => $get($row, $index, 'Date') ?: null,
        'plotted_by' => $get($row, $index, 'Plotted By') ?: null,
        'data_source' => $get($row, $index, 'Data Source') ?: null,
        'remarks' => $get($row, $index, 'Remarks') ?: null,
        'tc_crising' => $tcCrising !== '' ? $tcCrising : null,
        'photo_url' => $photo !== '' ? $photo : null,
        'preview_image_url' => $preview,
        'facilities' => [
            'ffps_storage' => $yesNo($get($row, $index, 'FFPs Storage')),
            'compost_pit' => $yesNo($get($row, $index, 'Compost Pit')),
            'sealed_latrine' => $yesNo($get($row, $index, 'Sealed')),
            'female_cr' => $yesNo($get($row, $index, 'Female CR')),
            'male_cr' => $yesNo($get($row, $index, 'Male CR')),
            'common_cr' => $yesNo($get($row, $index, 'Common CR')),
            'potable_water' => $yesNo($get($row, $index, 'Potable Water')),
            'potable_water_source' => $get($row, $index, 'Source of Potable Water') ?: null,
            'non_potable_water' => $yesNo($get($row, $index, 'Non-Potable Water')),
            'non_potable_water_source' => $get($row, $index, 'Source of Non-Potable Water') ?: null,
            'laundry_space' => $yesNo($get($row, $index, 'Laundry Space')),
            'health_station' => $yesNo($get($row, $index, 'Health Station')),
            'mrf' => $yesNo($get($row, $index, 'Material Recovery Facility')),
            'animals_area' => $yesNo($get($row, $index, 'Domestic & Livestock Animals Area')),
            'child_friendly_space' => $yesNo($get($row, $index, 'Child Friendly Space')),
            'women_friendly_space' => $yesNo($get($row, $index, 'Women - Friendly Space')),
            'couples_room' => $yesNo($get($row, $index, "Couple's Room")),
            'prayer_room' => $yesNo($get($row, $index, 'Prayer Room')),
            'community_kitchen' => $yesNo($get($row, $index, 'Community Kitchen')),
            'wash_facility' => $yesNo($get($row, $index, 'Wash Facility / Water Source')),
            'ramp_pwd' => $yesNo($get($row, $index, 'Ramp (PWDs)')),
            'help_desk' => $yesNo($get($row, $index, 'Help Desk')),
            'info_board' => $yesNo($get($row, $index, 'Info Board')),
        ],
        'amenities' => array_values(array_filter([
            $get($row, $index, 'Potable Water') === 'Yes' ? 'Potable water' : null,
            $get($row, $index, 'Female CR') === 'Yes' ? 'Female CR' : null,
            $get($row, $index, 'Male CR') === 'Yes' ? 'Male CR' : null,
            $get($row, $index, 'Community Kitchen') === 'Yes' ? 'Community kitchen' : null,
            $get($row, $index, 'Child Friendly Space') === 'Yes' ? 'Child-friendly space' : null,
            $get($row, $index, 'Women - Friendly Space') === 'Yes' ? 'Women-friendly space' : null,
            $get($row, $index, 'Ramp (PWDs)') === 'Yes' ? 'PWD ramp' : null,
            $get($row, $index, 'Health Station') === 'Yes' ? 'Health station' : null,
            $get($row, $index, 'FFPs Storage') === 'Yes' ? 'FFP storage' : null,
        ])),
    ];
}
fclose($handle);

usort($centers, static function (array $a, array $b): int {
    return [$a['municipality'], $a['barangay'], $a['name']]
        <=> [$b['municipality'], $b['barangay'], $b['name']];
});

$payload = [
    'source' => 'https://docs.google.com/spreadsheets/d/1N2fVODuyCAH7ZJ47TsC8-qic-CU43UMbi7Utphkp1Xo',
    'generated_at' => gmdate('c'),
    'excluded_tc_crising_closed' => $skippedClosed,
    'count' => count($centers),
    'centers' => $centers,
];

file_put_contents($outPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
echo 'Wrote '.count($centers)." centers to {$outPath} (skipped {$skippedClosed} TC CRISING CLOSED)\n";

$mergeScript = $root.'/scripts/merge_evac_center_photos.php';
if (is_file($mergeScript)) {
    passthru('php '.escapeshellarg($mergeScript), $mergeCode);
    if ($mergeCode !== 0) {
        fwrite(STDERR, "Photo merge exited with code {$mergeCode}\n");
    }
}
