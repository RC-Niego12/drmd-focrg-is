<?php

$path = dirname(__DIR__).'/storage/app/geo/looker_probe/sheet_gviz.txt';
$h = fopen($path, 'r');
$headers = fgetcsv($h, 0, ',', '"', '\\');
echo "headers:\n";
foreach ($headers as $i => $hname) {
    echo "{$i}\t{$hname}\n";
}
$photoCol = array_search('Link for Geotagged Photos', $headers, true);
$muniCol = array_search('MuniCity', $headers, true);
$tcCol = array_search('TC CRISING', $headers, true);
$want = ['Mainit', 'Alegria', 'Sison'];
$stats = [];
$samples = [];
while (($row = fgetcsv($h, 0, ',', '"', '\\')) !== false) {
    $muni = trim((string) ($row[$muniCol] ?? ''));
    if (! in_array($muni, $want, true)) {
        continue;
    }
    $tc = trim((string) ($row[$tcCol] ?? ''));
    if (strcasecmp($tc, 'CLOSED') === 0) {
        continue;
    }
    $photo = trim((string) ($row[$photoCol] ?? ''));
    $stats[$muni] = $stats[$muni] ?? ['rows' => 0, 'with_photo' => 0];
    $stats[$muni]['rows']++;
    if ($photo !== '') {
        $stats[$muni]['with_photo']++;
        if (count($samples[$muni] ?? []) < 3) {
            $samples[$muni][] = $photo;
        }
    }
}
fclose($h);
echo json_encode(['stats' => $stats, 'samples' => $samples], JSON_PRETTY_PRINT).PHP_EOL;

// Also list sheet tabs if present in htmlview
$html = file_get_contents(dirname(__DIR__).'/storage/app/geo/looker_probe/sheet_html.txt');
preg_match_all('/gid=(\d+)/', $html, $m);
echo 'gids='.implode(',', array_unique($m[1] ?? []))."\n";
preg_match_all('/name":"([^"]+)"/', $html, $names);
echo 'names sample: '.implode(', ', array_slice(array_unique($names[1] ?? []), 0, 20))."\n";
