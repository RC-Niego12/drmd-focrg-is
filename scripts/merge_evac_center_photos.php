<?php

/**
 * Merge GDrive photo links from per-LGU geotag sheets into the EC inventory snapshot.
 *
 * Expects CSVs at storage/app/geo/ec_photos/{mainit,alegria,sison}.csv
 *
 * Usage: php scripts/merge_evac_center_photos.php
 */

$root = dirname(__DIR__);
$snapshotPath = $root.'/database/data/caraga_evacuation_centers.json';
$csvDir = $root.'/storage/app/geo/ec_photos';
$sheets = require $root.'/config/evac_center_photo_sheets.php';

if (! is_file($snapshotPath)) {
    fwrite(STDERR, "Missing snapshot {$snapshotPath}\n");
    exit(1);
}

$payload = json_decode((string) file_get_contents($snapshotPath), true);
if (! is_array($payload) || ! is_array($payload['centers'] ?? null)) {
    fwrite(STDERR, "Invalid snapshot JSON\n");
    exit(1);
}

$normalize = static function (?string $value): string {
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/^(plgu|clgu|mlgu|lgu)\s+/', '', $value) ?? $value;
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
};

// Safe name variants only (no stripping of Hall/Gym/etc that collapses distinct ECs).
$nameVariants = static function (?string $value) use ($normalize): array {
    $exact = $normalize($value);
    if ($exact === '') {
        return [];
    }

    $variants = [$exact];
    if (str_starts_with($exact, 'parish ')) {
        $variants[] = trim(substr($exact, 7));
    } else {
        $variants[] = 'parish '.$exact;
    }
    if (str_starts_with($exact, 'brgy ')) {
        $variants[] = trim(substr($exact, 5));
    } else {
        $variants[] = 'brgy '.$exact;
    }

    return array_values(array_unique(array_filter($variants)));
};

$findColumn = static function (array $headers, array $patterns): ?string {
    foreach ($headers as $header) {
        $h = strtolower(trim((string) $header));
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $h)) {
                return (string) $header;
            }
        }
    }

    return null;
};

$extractDriveIds = static function (string $raw): array {
    $ids = [];
    if (preg_match_all('#/file/d/([a-zA-Z0-9_-]+)#', $raw, $m)) {
        $ids = array_merge($ids, $m[1]);
    }
    if (preg_match_all('#[?&]id=([a-zA-Z0-9_-]+)#', $raw, $m)) {
        $ids = array_merge($ids, $m[1]);
    }
    if (preg_match_all('#drive\.google\.com/open\?id=([a-zA-Z0-9_-]+)#', $raw, $m)) {
        $ids = array_merge($ids, $m[1]);
    }

    return array_values(array_unique(array_filter($ids)));
};

$toDisplayUrl = static function (string $fileId): string {
    return 'https://drive.google.com/uc?export=view&id='.$fileId;
};

$toOpenUrl = static function (string $fileId): string {
    return 'https://drive.google.com/file/d/'.$fileId.'/view';
};

$photoIndex = []; // exact lookup keys => photos[]
$metaByBarangayName = []; // muni|barangay|name => meta
$metaByUniqueName = []; // muni|name => meta only when unique in sheet
$nameCounts = []; // muni|name => count in geotag sheet
$geotagFacilities = []; // muni => list of facilities for fuzzy fallback
$stats = [];

$namesClose = static function (string $a, string $b) use ($normalize): bool {
    $a = $normalize($a);
    $b = $normalize($b);
    if ($a === '' || $b === '') {
        return false;
    }
    if ($a === $b) {
        return true;
    }
    $maxLen = max(strlen($a), strlen($b));
    if ($maxLen <= 0) {
        return false;
    }
    $distance = levenshtein($a, $b);
    if ($distance <= 2) {
        return true;
    }
    similar_text($a, $b, $percent);

    return $percent >= 94.0;
};

foreach ($sheets as $key => $sheet) {
    $csvPath = $csvDir.'/'.($sheet['csv'] ?? ($key.'.csv'));
    $muni = (string) ($sheet['sheet_municipality'] ?? $sheet['name'] ?? $key);
    $stats[$key] = [
        'csv' => is_file($csvPath),
        'rows' => 0,
        'with_links' => 0,
        'matched' => 0,
        'path' => $csvPath,
    ];

    if (! is_file($csvPath)) {
        fwrite(STDERR, "Missing CSV for {$key}: {$csvPath}\n");
        continue;
    }

    $handle = fopen($csvPath, 'r');
    $headers = fgetcsv($handle, 0, ',', '"', '\\');
    if (! is_array($headers)) {
        fclose($handle);
        fwrite(STDERR, "Empty CSV {$csvPath}\n");
        continue;
    }

    if (isset($headers[0])) {
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]) ?? $headers[0];
    }

    $nameCol = $findColumn($headers, [
        '/^evacuation\s*center/',
        '/\bec\s*name\b/',
        '/facility\s*name/',
        '/center\s*name/',
        '/^name$/',
    ]);
    $barangayCol = $findColumn($headers, ['/^barangay/', '/\bbrgy\b/']);
    $latCol = $findColumn($headers, ['/latitude/', '/_gps coordinates_latitude/']);
    $lngCol = $findColumn($headers, ['/longitude/', '/_gps coordinates_longitude/']);
    $linkCol = $findColumn($headers, [
        '/gdrive\s*links?/',
        '/google\s*drive/',
        '/drive\s*links?/',
        '/photo\s*links?/',
        '/geotagged\s*photos?/',
        '/^photos?$/',
        '/^links?$/',
    ]);
    $photoDocCol = $findColumn($headers, ['/photo\s*documentation/']);

    if ($nameCol === null || $linkCol === null) {
        fclose($handle);
        fwrite(STDERR, "{$key}: could not find name/GDrive columns. Headers: ".implode(' | ', $headers)."\n");
        continue;
    }

    echo "{$key}: name={$nameCol} | links={$linkCol}".($barangayCol ? " | barangay={$barangayCol}" : '').PHP_EOL;
    $index = array_flip($headers);

    $rows = [];
    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        $name = trim((string) ($row[$index[$nameCol]] ?? ''));
        if ($name === '') {
            continue;
        }
        $stats[$key]['rows']++;
        $barangay = $barangayCol ? trim((string) ($row[$index[$barangayCol]] ?? '')) : '';
        $lat = $latCol ? trim((string) ($row[$index[$latCol]] ?? '')) : '';
        $lng = $lngCol ? trim((string) ($row[$index[$lngCol]] ?? '')) : '';
        $linkRaw = trim((string) ($row[$index[$linkCol]] ?? ''));
        $photoDoc = $photoDocCol ? trim((string) ($row[$index[$photoDocCol]] ?? '')) : '';

        foreach ($nameVariants($name) as $variant) {
            $countKey = $normalize($muni).'|'.$variant;
            $nameCounts[$countKey] = ($nameCounts[$countKey] ?? 0) + 1;
        }

        $rows[] = compact('name', 'barangay', 'lat', 'lng', 'linkRaw', 'photoDoc');
    }
    fclose($handle);

    foreach ($rows as $row) {
        $name = $row['name'];
        $barangay = $row['barangay'];
        $lat = $row['lat'];
        $lng = $row['lng'];
        $linkRaw = $row['linkRaw'];
        $photoDoc = $row['photoDoc'];

        $metaPayload = [
            'barangay' => $barangay,
            'lat' => is_numeric($lat) ? round((float) $lat, 7) : null,
            'lng' => is_numeric($lng) ? round((float) $lng, 7) : null,
            'sheet_name' => $name,
        ];

        foreach ($nameVariants($name) as $variant) {
            if ($barangay !== '') {
                $metaByBarangayName[$normalize($muni).'|'.$normalize($barangay).'|'.$variant] = $metaPayload;
            }
            $exactNameKey = $normalize($muni).'|'.$variant;
            if (($nameCounts[$exactNameKey] ?? 0) === 1) {
                $metaByUniqueName[$exactNameKey] = $metaPayload;
            }
        }

        if ($linkRaw === '' && $photoDoc === '') {
            continue;
        }
        $stats[$key]['with_links']++;

        $ids = $extractDriveIds($linkRaw.($photoDoc !== '' ? ' '.$photoDoc : ''));
        $photos = [];
        if ($photoDoc !== '' && preg_match('#^https?://#i', $photoDoc)) {
            $open = $ids[0] ?? null;
            $photos[] = [
                'url' => $photoDoc,
                'open_url' => $open ? $toOpenUrl($open) : $photoDoc,
                'source' => $key,
            ];
        }
        foreach ($ids as $id) {
            $photos[] = [
                'url' => $toDisplayUrl($id),
                'open_url' => $toOpenUrl($id),
                'drive_id' => $id,
                'source' => $key,
            ];
        }
        if ($photos === [] && preg_match('#^https?://#i', $linkRaw)) {
            $photos[] = [
                'url' => $linkRaw,
                'open_url' => $linkRaw,
                'source' => $key,
            ];
        }
        if ($photos === []) {
            continue;
        }

        // Deduplicate photo list for this row.
        $seenPhoto = [];
        $uniquePhotos = [];
        foreach ($photos as $photo) {
            $k = (string) ($photo['open_url'] ?? $photo['url'] ?? '');
            if ($k === '' || isset($seenPhoto[$k])) {
                continue;
            }
            $seenPhoto[$k] = true;
            $uniquePhotos[] = $photo;
        }

        $keys = [];
        foreach ($nameVariants($name) as $variant) {
            if ($barangay !== '') {
                $keys[] = $normalize($muni).'|'.$normalize($barangay).'|'.$variant;
            }
            $exactNameKey = $normalize($muni).'|'.$variant;
            // Name-only photo index only when that name is unique in the geotag sheet.
            if (($nameCounts[$exactNameKey] ?? 0) === 1) {
                $keys[] = $exactNameKey;
            }
        }

        foreach (array_unique($keys) as $mapKey) {
            $photoIndex[$mapKey] = array_values(array_merge($photoIndex[$mapKey] ?? [], $uniquePhotos));
        }

        $geotagFacilities[$normalize($muni)][] = [
            'barangay' => $barangay,
            'name' => $name,
            'photos' => $uniquePhotos,
            'meta' => $metaPayload,
        ];
    }
}

$matchedCenters = 0;
$withPhotos = 0;
$barangayFixes = 0;

foreach ($payload['centers'] as &$center) {
    $muni = (string) ($center['municipality'] ?? '');
    $name = (string) ($center['name'] ?? '');
    $barangay = (string) ($center['barangay'] ?? '');

    $meta = null;
    $photos = [];
    foreach ($nameVariants($name) as $variant) {
        $brgyKey = $normalize($muni).'|'.$normalize($barangay).'|'.$variant;
        if ($barangay !== '' && isset($metaByBarangayName[$brgyKey])) {
            $meta = $metaByBarangayName[$brgyKey];
        }
        if ($barangay !== '' && isset($photoIndex[$brgyKey])) {
            $photos = $photoIndex[$brgyKey];
            break;
        }
    }
    if ($photos === []) {
        foreach ($nameVariants($name) as $variant) {
            $nameKey = $normalize($muni).'|'.$variant;
            if (isset($photoIndex[$nameKey])) {
                $photos = $photoIndex[$nameKey];
                $meta ??= $metaByUniqueName[$nameKey] ?? null;
                break;
            }
        }
    }
    if ($meta === null) {
        foreach ($nameVariants($name) as $variant) {
            $nameKey = $normalize($muni).'|'.$variant;
            if (isset($metaByUniqueName[$nameKey])) {
                $meta = $metaByUniqueName[$nameKey];
                break;
            }
        }
    }

    // Fuzzy fallback within the same municipality + barangay for typos
    // (e.g. inventory "Multi-Purposd" vs geotag "Multi-Purposed").
    if ($photos === []) {
        $candidates = [];
        foreach ($geotagFacilities[$normalize($muni)] ?? [] as $facility) {
            if ($barangay !== '' && $normalize($facility['barangay'] ?? '') !== $normalize($barangay)) {
                continue;
            }
            if (! $namesClose($name, (string) ($facility['name'] ?? ''))) {
                continue;
            }
            $candidates[] = $facility;
        }
        if (count($candidates) === 1) {
            $photos = $candidates[0]['photos'] ?? [];
            $meta = $candidates[0]['meta'] ?? $meta;
            if (! empty($candidates[0]['name'])) {
                $center['name'] = $candidates[0]['name'];
                $name = $candidates[0]['name'];
            }
        }
    }

    // Only correct barangay when inventory barangay clearly mismatches a unique geotag match.
    $didBarangayFix = false;
    if (is_array($meta) && ($meta['barangay'] ?? '') !== '' && $normalize($meta['barangay']) !== $normalize($barangay)) {
        $uniqueHit = false;
        foreach ($nameVariants($name) as $variant) {
            if (isset($metaByUniqueName[$normalize($muni).'|'.$variant])) {
                $uniqueHit = true;
                break;
            }
        }
        if ($uniqueHit) {
            $center['barangay'] = $meta['barangay'];
            $barangay = $meta['barangay'];
            $barangayFixes++;
            $didBarangayFix = true;
        }
    }

    if (is_array($meta) && ($meta['lat'] ?? null) !== null && ($meta['lng'] ?? null) !== null) {
        $sameFacility = $normalize((string) ($meta['barangay'] ?? '')) === $normalize($barangay);
        if ($sameFacility || $didBarangayFix) {
            $center['lat'] = $meta['lat'];
            $center['lng'] = $meta['lng'];
            $center['preview_image_url'] = sprintf(
                'https://staticmap.openstreetmap.de/staticmap.php?center=%s,%s&zoom=16&size=720x420&maptype=mapnik&markers=%s,%s,red-pushpin',
                $meta['lat'],
                $meta['lng'],
                $meta['lat'],
                $meta['lng'],
            );
        }
    }

    $seen = [];
    $unique = [];
    foreach ($photos as $photo) {
        $k = (string) ($photo['open_url'] ?? $photo['url'] ?? '');
        if ($k === '' || isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        $unique[] = $photo;
    }

    if ($unique === []) {
        continue;
    }

    $matchedCenters++;
    $withPhotos += count($unique);
    $primary = $unique[0];
    $center['photo_url'] = $primary['url'] ?? null;
    $center['photo_open_url'] = $primary['open_url'] ?? $primary['url'] ?? null;
    $center['photo_urls'] = array_values(array_map(
        static fn (array $p): string => (string) ($p['url'] ?? ''),
        $unique
    ));
    $center['photo_open_urls'] = array_values(array_map(
        static fn (array $p): string => (string) ($p['open_url'] ?? $p['url'] ?? ''),
        $unique
    ));
    $center['photo_source'] = 'lgu_geotag_sheet';
}
unset($center);

// Deduplicate inventory rows that share municipality + barangay + name + near-identical coordinates.
$deduped = [];
$seenFacility = [];
$removedDupes = 0;
foreach ($payload['centers'] as $center) {
    $lat = isset($center['lat']) ? round((float) $center['lat'], 5) : null;
    $lng = isset($center['lng']) ? round((float) $center['lng'], 5) : null;
    $facilityKey = $normalize($center['municipality'] ?? '').'|'.$normalize($center['barangay'] ?? '').'|'.$normalize($center['name'] ?? '').'|'.$lat.'|'.$lng;
    if (isset($seenFacility[$facilityKey])) {
        $removedDupes++;
        // Prefer the copy that already has a geotag photo.
        $existingIndex = $seenFacility[$facilityKey];
        $existing = $deduped[$existingIndex];
        if (($existing['photo_source'] ?? null) !== 'lgu_geotag_sheet' && ($center['photo_source'] ?? null) === 'lgu_geotag_sheet') {
            $deduped[$existingIndex] = $center;
        }
        continue;
    }
    $seenFacility[$facilityKey] = count($deduped);
    $deduped[] = $center;
}
$payload['centers'] = array_values($deduped);
$payload['count'] = count($deduped);

foreach ($stats as $key => $stat) {
    if (! $stat['csv']) {
        continue;
    }
    $muni = $sheets[$key]['sheet_municipality'] ?? $key;
    $matched = 0;
    foreach ($payload['centers'] as $center) {
        if (strcasecmp((string) ($center['municipality'] ?? ''), (string) $muni) !== 0) {
            continue;
        }
        if (($center['photo_source'] ?? null) === 'lgu_geotag_sheet') {
            $matched++;
        }
    }
    $stats[$key]['matched'] = $matched;
}

$payload['photo_sources'] = [];
foreach ($sheets as $key => $sheet) {
    $payload['photo_sources'][] = [
        'key' => $key,
        'name' => $sheet['name'] ?? $key,
        'source_url' => $sheet['source_url'] ?? null,
        'csv_present' => (bool) ($stats[$key]['csv'] ?? false),
        'rows' => (int) ($stats[$key]['rows'] ?? 0),
        'with_links' => (int) ($stats[$key]['with_links'] ?? 0),
        'matched_centers' => (int) ($stats[$key]['matched'] ?? 0),
    ];
}
$payload['photos_merged_at'] = gmdate('c');

file_put_contents($snapshotPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

echo json_encode([
    'matched_centers' => $matchedCenters,
    'photo_links_applied' => $withPhotos,
    'barangay_fixes' => $barangayFixes,
    'removed_near_duplicate_centers' => $removedDupes,
    'stats' => $stats,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
