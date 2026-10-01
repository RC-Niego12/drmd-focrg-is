<?php

$urls = [
    'mainit_embed' => 'https://datastudio.google.com/embed/reporting/578ac825-54e4-40e5-a2ea-05b05b9e7ae1/page/ZrA2F',
    'alegria_embed' => 'https://datastudio.google.com/embed/reporting/13b0d476-4174-4362-9c22-8be9be1502f0/page/ZrA2F',
    'sison_embed' => 'https://datastudio.google.com/embed/reporting/eada401b-b81c-4628-b9e5-0c327bbd8a2d/page/ZrA2F',
    'sheet_html' => 'https://docs.google.com/spreadsheets/d/1N2fVODuyCAH7ZJ47TsC8-qic-CU43UMbi7Utphkp1Xo/htmlview',
    'sheet_gviz' => 'https://docs.google.com/spreadsheets/d/1N2fVODuyCAH7ZJ47TsC8-qic-CU43UMbi7Utphkp1Xo/gviz/tq?tqx=out:csv',
];

$dir = dirname(__DIR__).'/storage/app/geo/looker_probe';
if (! is_dir($dir)) {
    mkdir($dir, 0775, true);
}

foreach ($urls as $name => $url) {
    $path = $dir.'/'.$name.'.txt';
    $cmd = 'curl.exe -sL -A "Mozilla/5.0" '.escapeshellarg($url).' -o '.escapeshellarg($path);
    exec($cmd, $out, $code);
    $size = is_file($path) ? filesize($path) : 0;
    $snippet = is_file($path) ? substr((string) file_get_contents($path), 0, 400) : '';
    echo "=== {$name} code={$code} size={$size} ===\n";
    // extract interesting URLs
    if (is_file($path)) {
        $body = file_get_contents($path);
        preg_match_all('#https?://[^\"\'\s<>]+(?:jpg|jpeg|png|webp|googleusercontent|drive\.google|photos\.google|spreadsheets)[^\"\'\s<>]*#i', $body, $m);
        $links = array_values(array_unique($m[0] ?? []));
        echo 'links='.count($links)."\n";
        foreach (array_slice($links, 0, 15) as $link) {
            echo $link."\n";
        }
        if (stripos($body, 'Identified') !== false || stripos($body, 'Geotagged') !== false || stripos($body, 'Evacuation') !== false) {
            echo "HAS EC TITLE TEXT\n";
        }
        if (stripos($body, 'Sign in') !== false && stripos($body, 'Data Studio') !== false) {
            echo "REQUIRES SIGN IN\n";
        }
    }
    echo "\n";
}
