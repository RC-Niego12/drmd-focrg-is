<?php

$root = dirname(__DIR__);
$snap = json_decode(file_get_contents($root.'/database/data/caraga_evacuation_centers.json'), true);

$normalize = static function (?string $value): string {
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
};

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

$compare = static function (string $muni, string $csvPath) use ($snap, $normalize, $nameVariants, $findColumn): void {
    $inv = array_values(array_filter(
        $snap['centers'],
        fn ($c) => strcasecmp((string) ($c['municipality'] ?? ''), $muni) === 0
    ));
    $invWithPhoto = array_values(array_filter($inv, fn ($c) => ($c['photo_source'] ?? '') === 'lgu_geotag_sheet'));

    echo "======== {$muni} ========\n";
    echo 'inventory_ecs='.count($inv).' inventory_with_photo='.count($invWithPhoto).PHP_EOL;

    if (! is_file($csvPath)) {
        echo "MISSING CSV {$csvPath}\n";

        return;
    }

    $h = fopen($csvPath, 'r');
    $headers = fgetcsv($h, 0, ',', '"', '\\');
    if (isset($headers[0])) {
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]) ?? $headers[0];
    }
    $nameCol = $findColumn($headers, ['/^evacuation\s*center/', '/^name$/']);
    $brgyCol = $findColumn($headers, ['/^barangay/']);
    $linkCol = $findColumn($headers, ['/gdrive\s*links?/', '/photo/']);
    $index = array_flip($headers);

    $csvRows = [];
    while (($row = fgetcsv($h, 0, ',', '"', '\\')) !== false) {
        $name = trim((string) ($row[$index[$nameCol]] ?? ''));
        if ($name === '') {
            continue;
        }
        $brgy = trim((string) ($row[$index[$brgyCol]] ?? ''));
        $link = trim((string) ($row[$index[$linkCol]] ?? ''));
        $csvRows[] = compact('name', 'brgy', 'link');
    }
    fclose($h);

    $csvWithLink = array_values(array_filter($csvRows, fn ($r) => $r['link'] !== ''));
    echo 'geotag_rows='.count($csvRows).' geotag_with_link='.count($csvWithLink).PHP_EOL;

    $matchInv = static function (array $rows, string $brgy, string $name) use ($normalize, $nameVariants): ?array {
        $brgyN = $normalize($brgy);
        foreach ($rows as $row) {
            if ($normalize($row['barangay'] ?? '') !== $brgyN && $brgyN !== '') {
                // allow later name-only unique
            }
            $nameOk = false;
            foreach ($nameVariants($name) as $v) {
                foreach ($nameVariants($row['name'] ?? '') as $rv) {
                    if ($v === $rv) {
                        $nameOk = true;
                        break 2;
                    }
                }
            }
            if (! $nameOk) {
                continue;
            }
            if ($brgyN === '' || $normalize($row['barangay'] ?? '') === $brgyN) {
                return $row;
            }
        }
        // name-only fallback if unique
        $hits = [];
        foreach ($rows as $row) {
            foreach ($nameVariants($name) as $v) {
                foreach ($nameVariants($row['name'] ?? '') as $rv) {
                    if ($v === $rv) {
                        $hits[] = $row;
                        break 2;
                    }
                }
            }
        }
        if (count($hits) === 1) {
            return $hits[0];
        }

        return null;
    };

    $invMatched = 0;
    $invMissing = [];
    foreach ($inv as $center) {
        $hit = null;
        foreach ($csvRows as $row) {
            $nameOk = false;
            foreach ($nameVariants($center['name']) as $v) {
                foreach ($nameVariants($row['name']) as $rv) {
                    if ($v === $rv) {
                        $nameOk = true;
                        break 2;
                    }
                }
            }
            if (! $nameOk) {
                continue;
            }
            if ($normalize($center['barangay']) === $normalize($row['brgy'])) {
                $hit = $row;
                break;
            }
        }
        if (! $hit) {
            // unique name
            $hits = [];
            foreach ($csvRows as $row) {
                foreach ($nameVariants($center['name']) as $v) {
                    foreach ($nameVariants($row['name']) as $rv) {
                        if ($v === $rv) {
                            $hits[] = $row;
                            break 2;
                        }
                    }
                }
            }
            if (count($hits) === 1) {
                $hit = $hits[0];
            }
        }
        if ($hit) {
            $invMatched++;
        } else {
            $invMissing[] = ($center['barangay'] ?? '').' | '.($center['name'] ?? '');
        }
    }

    $csvMatched = 0;
    $csvMissing = [];
    foreach ($csvRows as $row) {
        $hit = null;
        foreach ($inv as $center) {
            $nameOk = false;
            foreach ($nameVariants($row['name']) as $v) {
                foreach ($nameVariants($center['name']) as $rv) {
                    if ($v === $rv) {
                        $nameOk = true;
                        break 2;
                    }
                }
            }
            if (! $nameOk) {
                continue;
            }
            if ($normalize($row['brgy']) === $normalize($center['barangay'])) {
                $hit = $center;
                break;
            }
        }
        if (! $hit) {
            $hits = [];
            foreach ($inv as $center) {
                foreach ($nameVariants($row['name']) as $v) {
                    foreach ($nameVariants($center['name']) as $rv) {
                        if ($v === $rv) {
                            $hits[] = $center;
                            break 2;
                        }
                    }
                }
            }
            if (count($hits) === 1) {
                $hit = $hits[0];
            }
        }
        if ($hit) {
            $csvMatched++;
        } else {
            $csvMissing[] = $row['brgy'].' | '.$row['name'].' | link='.($row['link'] !== '' ? 'yes' : 'no');
        }
    }

    echo "inv_matched_to_geotag={$invMatched} inv_not_in_geotag=".count($invMissing).PHP_EOL;
    foreach ($invMissing as $line) {
        echo "  INV-ONLY: {$line}\n";
    }
    echo "geotag_matched_to_inv={$csvMatched} geotag_not_in_inv=".count($csvMissing).PHP_EOL;
    foreach ($csvMissing as $line) {
        echo "  CSV-ONLY: {$line}\n";
    }

    $noPhoto = [];
    foreach ($inv as $center) {
        if (($center['photo_source'] ?? '') !== 'lgu_geotag_sheet') {
            $noPhoto[] = ($center['barangay'] ?? '').' | '.($center['name'] ?? '');
        }
    }
    echo 'inv_without_photo='.count($noPhoto).PHP_EOL;
    foreach ($noPhoto as $line) {
        echo "  NO-PHOTO: {$line}\n";
    }
    echo PHP_EOL;
};

$compare('Alegria', $root.'/storage/app/geo/ec_photos/alegria.csv');
$compare('Sison', $root.'/storage/app/geo/ec_photos/sison.csv');
$compare('Mainit', $root.'/storage/app/geo/ec_photos/mainit.csv');
