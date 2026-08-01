<?php

namespace App\Services;

use App\Models\LguDirectoryEntry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class LswdoPhotoImportService
{
    private const SHEETS = [
        'ADN' => ['worksheet' => 'xl/worksheets/sheet1.xml', 'drawing' => 'xl/drawings/drawing1.xml'],
        'ADS' => ['worksheet' => 'xl/worksheets/sheet2.xml', 'drawing' => 'xl/drawings/drawing2.xml'],
        'SDN' => ['worksheet' => 'xl/worksheets/sheet3.xml', 'drawing' => 'xl/drawings/drawing3.xml'],
        'SDS' => ['worksheet' => 'xl/worksheets/sheet4.xml', 'drawing' => 'xl/drawings/drawing4.xml'],
        'PDI' => ['worksheet' => 'xl/worksheets/sheet5.xml', 'drawing' => 'xl/drawings/drawing5.xml'],
    ];

    public function import(?string $url = null): array
    {
        $temporary = tempnam(sys_get_temp_dir(), 'dromis-lswdo-photos-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to create a temporary workbook file.');
        }

        try {
            Http::withOptions(['sink' => $temporary])
                ->retry(2, 1000, throw: false)
                ->timeout(300)
                ->get($url ?: $this->workbookUrl())
                ->throw();

            return $this->importFile($temporary);
        } finally {
            @unlink($temporary);
        }
    }

    private function workbookUrl(): string
    {
        $spreadsheetId = (string) config('services.google_sheets.regional_directory_spreadsheet_id');
        if ($spreadsheetId === '') {
            throw new RuntimeException('The live regional directory spreadsheet ID is not configured.');
        }

        return "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/export?format=xlsx";
    }

    public function importFile(string $workbookPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($workbookPath) !== true) {
            throw new RuntimeException('The LSWDO photo workbook could not be opened.');
        }

        try {
            $sharedStrings = $this->sharedStrings($zip);
            $candidates = [];

            foreach (self::SHEETS as $sheet => $paths) {
                $rows = $this->worksheetRows($zip, $paths['worksheet'], $sharedStrings);
                $relationships = $this->drawingRelationships($zip, $paths['drawing']);

                foreach ($this->drawingAnchors($zip, $paths['drawing']) as $anchor) {
                    if ($anchor['column'] !== 7 || ! isset($rows[$anchor['row']])) {
                        continue;
                    }

                    $row = $rows[$anchor['row']];
                    $lgu = trim((string) ($row['D'] ?? ''));
                    $name = trim((string) ($row['F'] ?? ''));
                    $media = $relationships[$anchor['relationship_id']] ?? null;

                    if ($lgu === '' || $name === '' || ! $media) {
                        continue;
                    }

                    $candidates[] = compact('sheet', 'lgu', 'name', 'media') + ['row' => $anchor['row']];
                }
            }

            $sharedMedia = collect($candidates)
                ->groupBy('media')
                ->filter(fn ($items) => $items->pluck('name')->map(fn ($name) => $this->personKey($name))->unique()->count() > 1)
                ->keys()
                ->all();

            $entries = LguDirectoryEntry::query()
                ->with('officials')
                ->whereIn('source_sheet', array_keys(self::SHEETS))
                ->get();

            $summary = [
                'candidates' => count($candidates),
                'imported' => 0,
                'unchanged' => 0,
                'placeholder_skipped' => 0,
                'stale_cleared' => 0,
                'lgu_unmatched' => [],
                'name_mismatched' => [],
                'invalid_images' => [],
            ];
            $verifiedEntryIds = [];

            foreach ($candidates as $candidate) {
                if (in_array($candidate['media'], $sharedMedia, true)) {
                    $summary['placeholder_skipped']++;
                    continue;
                }

                $entry = $entries->first(fn ($item) => $item->source_sheet === $candidate['sheet']
                    && $this->placeKey($item->override_lgu_name ?: $item->lgu_name) === $this->placeKey($candidate['lgu']));
                if (! $entry) {
                    $needle = $this->placeKey($candidate['lgu']);
                    $close = $entries
                        ->where('source_sheet', $candidate['sheet'])
                        ->map(fn ($item) => [
                            'entry' => $item,
                            'distance' => levenshtein($needle, $this->placeKey($item->override_lgu_name ?: $item->lgu_name)),
                        ])
                        ->filter(fn ($match) => $match['distance'] <= 1)
                        ->sortBy('distance')
                        ->values();
                    if ($close->count() === 1) {
                        $entry = $close->first()['entry'];
                    }
                }

                if (! $entry) {
                    $summary['lgu_unmatched'][] = $candidate;
                    continue;
                }

                $official = $entry->officials->firstWhere('role', 'lswd_officer');
                $directoryName = $official?->override_name ?: $official?->name;
                if (! $directoryName || $this->personKey($directoryName) !== $this->personKey($candidate['name'])) {
                    $summary['name_mismatched'][] = $candidate + ['directory_name' => $directoryName];
                    continue;
                }

                $bytes = $zip->getFromName('xl/media/'.basename($candidate['media']));
                $imageInfo = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
                if (! $imageInfo || ! in_array($imageInfo['mime'] ?? null, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                    $summary['invalid_images'][] = $candidate;
                    continue;
                }
                $verifiedEntryIds[] = $entry->id;

                $extension = match ($imageInfo['mime']) {
                    'image/jpeg' => 'jpg',
                    'image/webp' => 'webp',
                    default => 'png',
                };
                $path = 'lgu-officials/sheet-lswdo/'.$candidate['sheet'].'-'
                    .$entry->id.'-'.substr(hash('sha256', $this->personKey($candidate['name'])), 0, 12).'.'.$extension;

                if ($entry->lswd_photo_path === $path && Storage::disk('public')->exists($path)
                    && hash_equals(hash('sha256', Storage::disk('public')->get($path)), hash('sha256', $bytes))) {
                    $summary['unchanged']++;
                    continue;
                }

                $previousPath = $entry->lswd_photo_path;
                Storage::disk('public')->put($path, $bytes);
                $entry->forceFill(['lswd_photo_path' => $path])->save();
                if ($previousPath && $previousPath !== $path && str_starts_with($previousPath, 'lgu-officials/sheet-lswdo/')) {
                    Storage::disk('public')->delete($previousPath);
                }
                $summary['imported']++;
            }

            foreach ($entries as $entry) {
                $path = $entry->lswd_photo_path;
                if (! $path || ! str_starts_with($path, 'lgu-officials/sheet-lswdo/')
                    || in_array($entry->id, $verifiedEntryIds, true)) {
                    continue;
                }
                Storage::disk('public')->delete($path);
                $entry->forceFill(['lswd_photo_path' => null])->save();
                $summary['stale_cleared']++;
            }

            return $summary;
        } finally {
            $zip->close();
        }
    }

    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (! is_string($xml)) {
            return [];
        }

        $document = $this->xml($xml);
        $xpath = new \DOMXPath($document);
        $strings = [];
        foreach ($xpath->query('//*[local-name()="si"]') as $item) {
            $parts = [];
            foreach ($xpath->query('.//*[local-name()="t"]', $item) as $text) {
                $parts[] = $text->textContent;
            }
            $strings[] = implode('', $parts);
        }
        return $strings;
    }

    private function worksheetRows(ZipArchive $zip, string $path, array $sharedStrings): array
    {
        $document = $this->xml($this->requiredEntry($zip, $path));
        $xpath = new \DOMXPath($document);
        $rows = [];

        foreach ($xpath->query('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
            $rowNumber = (int) $row->getAttribute('r');
            foreach ($xpath->query('./*[local-name()="c"]', $row) as $cell) {
                $reference = $cell->getAttribute('r');
                if (! preg_match('/^([A-Z]+)\d+$/', $reference, $match) || ! in_array($match[1], ['D', 'F'], true)) {
                    continue;
                }
                $value = $xpath->query('./*[local-name()="v"]', $cell)->item(0)?->textContent ?? '';
                if ($cell->getAttribute('t') === 's') {
                    $value = $sharedStrings[(int) $value] ?? '';
                } elseif ($cell->getAttribute('t') === 'inlineStr') {
                    $value = $xpath->query('.//*[local-name()="t"]', $cell)->item(0)?->textContent ?? '';
                }
                $rows[$rowNumber][$match[1]] = $value;
            }
        }
        return $rows;
    }

    private function drawingRelationships(ZipArchive $zip, string $drawingPath): array
    {
        $relationshipsPath = dirname($drawingPath).'/_rels/'.basename($drawingPath).'.rels';
        $document = $this->xml($this->requiredEntry($zip, $relationshipsPath));
        $xpath = new \DOMXPath($document);
        $relationships = [];
        foreach ($xpath->query('//*[local-name()="Relationship"]') as $relationship) {
            if (str_ends_with($relationship->getAttribute('Type'), '/image')) {
                $relationships[$relationship->getAttribute('Id')] = basename($relationship->getAttribute('Target'));
            }
        }
        return $relationships;
    }

    private function drawingAnchors(ZipArchive $zip, string $path): array
    {
        $document = $this->xml($this->requiredEntry($zip, $path));
        $xpath = new \DOMXPath($document);
        $anchors = [];
        foreach ($xpath->query('//*[local-name()="oneCellAnchor" or local-name()="twoCellAnchor"]') as $anchor) {
            $column = (int) ($xpath->query('./*[local-name()="from"]/*[local-name()="col"]', $anchor)->item(0)?->textContent ?? -1);
            $row = (int) ($xpath->query('./*[local-name()="from"]/*[local-name()="row"]', $anchor)->item(0)?->textContent ?? -1) + 1;
            $blip = $xpath->query('.//*[local-name()="blip"]', $anchor)->item(0);
            $relationshipId = $blip?->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed');
            if ($relationshipId) {
                $anchors[] = ['column' => $column, 'row' => $row, 'relationship_id' => $relationshipId];
            }
        }
        return $anchors;
    }

    private function requiredEntry(ZipArchive $zip, string $path): string
    {
        $contents = $zip->getFromName($path);
        if (! is_string($contents)) {
            throw new RuntimeException("Workbook entry {$path} was not found.");
        }
        return $contents;
    }

    private function xml(string $contents): \DOMDocument
    {
        $document = new \DOMDocument();
        if (! @$document->loadXML($contents, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('An invalid workbook XML document was encountered.');
        }
        return $document;
    }

    private function placeKey(?string $value): string
    {
        return Str::of((string) $value)
            ->ascii()
            ->upper()
            ->replaceMatches('/\([^)]*\)/', '')
            ->replaceMatches('/^(?:PROVINCE|CITY)\s+OF\s+/', '')
            ->replaceMatches('/\s+CITY$/', '')
            ->replaceMatches('/[^A-Z0-9]+/', '')
            ->toString();
    }

    private function personKey(?string $value): string
    {
        return Str::of((string) $value)
            ->ascii()
            ->upper()
            ->replaceMatches('/\b(?:HON|HONORABLE|MS|MRS|MR|DR|ATTY)\b\.?/', ' ')
            ->replaceMatches('/\b(?:RSW|MSSW|MSW|CESE|JD|DPA|PHD|MPA)\b\.?/', ' ')
            ->replaceMatches('/[^A-Z0-9]+/', '')
            ->toString();
    }
}
