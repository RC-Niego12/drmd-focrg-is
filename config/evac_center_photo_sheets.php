<?php

/**
 * Per-LGU geotagged EC photo sheets (GDrive Links column).
 * Export each tab as CSV into storage/app/geo/ec_photos/{key}.csv
 * then run: php scripts/merge_evac_center_photos.php
 *
 * @return array<string, array{name:string,sheet_municipality:string,spreadsheet_id:string,gid:string,csv:string,source_url:string}>
 */
return [
    'mainit' => [
        'name' => 'Mainit',
        'sheet_municipality' => 'Mainit',
        'spreadsheet_id' => '1Hubq318ZrzO0JGKK5zs85PfqVoeMPIhga64odcOawvA',
        'gid' => '1191063750',
        'csv' => 'mainit.csv',
        'source_url' => 'https://docs.google.com/spreadsheets/d/1Hubq318ZrzO0JGKK5zs85PfqVoeMPIhga64odcOawvA/edit?gid=1191063750#gid=1191063750',
    ],
    'alegria' => [
        'name' => 'Alegria',
        'sheet_municipality' => 'Alegria',
        'spreadsheet_id' => '1HLJHWK5kC7VGwUte5wqJ2PQnMpO0a4qlmrSiW87iOLM',
        'gid' => '1290314478',
        'csv' => 'alegria.csv',
        'source_url' => 'https://docs.google.com/spreadsheets/d/1HLJHWK5kC7VGwUte5wqJ2PQnMpO0a4qlmrSiW87iOLM/edit?gid=1290314478#gid=1290314478',
    ],
    'sison' => [
        'name' => 'Sison',
        'sheet_municipality' => 'Sison',
        'spreadsheet_id' => '1PasvKlz4sztBMSgO3ch9u9-60F1HrQCgnkNNnVJgCic',
        'gid' => '1960709240',
        'csv' => 'sison.csv',
        'source_url' => 'https://docs.google.com/spreadsheets/d/1PasvKlz4sztBMSgO3ch9u9-60F1HrQCgnkNNnVJgCic/edit?gid=1960709240#gid=1960709240',
    ],
];
