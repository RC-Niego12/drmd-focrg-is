<?php

use App\Models\LguDirectoryEntry;
use App\Services\LswdoPhotoImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('imports an LSWDO portrait only when the anchored LGU row and officer name both match', function (): void {
    Storage::fake('public');

    $entry = LguDirectoryEntry::query()->create([
        'source_sheet' => 'ADN',
        'lgu_name' => 'TEST LGU',
        'is_active' => true,
    ]);
    $entry->officials()->create([
        'role' => 'lswd_officer',
        'name' => 'MS. JUANA D. SANTOS, RSW',
        'position_designation' => 'MSWDO',
    ]);
    $staleEntry = LguDirectoryEntry::query()->create([
        'source_sheet' => 'ADN',
        'lgu_name' => 'OLD LGU',
        'lswd_photo_path' => 'lgu-officials/sheet-lswdo/ADN-old.png',
        'is_active' => true,
    ]);
    Storage::disk('public')->put($staleEntry->lswd_photo_path, 'old image');

    $workbook = tempnam(sys_get_temp_dir(), 'lswdo-photo-test-');
    $zip = new ZipArchive();
    $zip->open($workbook, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('xl/sharedStrings.xml', <<<'XML'
<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>TEST LGU</t></si><si><t>JUANA D. SANTOS</t></si></sst>
XML);

    for ($index = 1; $index <= 5; $index++) {
        $sheetData = $index === 1
            ? '<row r="3"><c r="D3" t="s"><v>0</v></c><c r="F3" t="s"><v>1</v></c></row>'
            : '';
        $zip->addFromString("xl/worksheets/sheet{$index}.xml", '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetData.'</sheetData></worksheet>');

        $drawing = $index === 1
            ? '<xdr:oneCellAnchor><xdr:from><xdr:col>7</xdr:col><xdr:row>2</xdr:row></xdr:from><xdr:pic><xdr:blipFill><a:blip r:embed="rId1"/></xdr:blipFill></xdr:pic></xdr:oneCellAnchor>'
            : '';
        $zip->addFromString("xl/drawings/drawing{$index}.xml", '<?xml version="1.0"?><xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'.$drawing.'</xdr:wsDr>');

        $relationship = $index === 1
            ? '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/photo.png"/>'
            : '';
        $zip->addFromString("xl/drawings/_rels/drawing{$index}.xml.rels", '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relationship.'</Relationships>');
    }

    $zip->addFromString('xl/media/photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
    $zip->close();

    try {
        $summary = app(LswdoPhotoImportService::class)->importFile($workbook);
    } finally {
        @unlink($workbook);
    }

    $entry->refresh();
    expect($summary['imported'])->toBe(1)
        ->and($summary['stale_cleared'])->toBe(1)
        ->and($summary['name_mismatched'])->toBe([])
        ->and($entry->lswd_photo_path)->not->toBeNull()
        ->and($staleEntry->fresh()->lswd_photo_path)->toBeNull();
    Storage::disk('public')->assertExists($entry->lswd_photo_path);
    Storage::disk('public')->assertMissing('lgu-officials/sheet-lswdo/ADN-old.png');
});
