<?php

use App\Models\AssistanceRequest;
use App\Models\FniLibraryItem;
use App\Models\User;
use App\Services\ResponseLetterDocumentService;
use App\Support\RequestedGoodsTypeSummary;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('summarizes Tubod fire request items as brief food and non-food prose', function (): void {
    $ffp = FniLibraryItem::query()->create([
        'item_category' => 'Family Food Packs',
        'item_name' => 'Family Food Pack',
        'brand_description' => '',
        'unit_of_measure' => 'box',
    ]);
    $water = FniLibraryItem::query()->create([
        'item_category' => 'Food Items',
        'item_name' => 'Water',
        'brand_description' => 'Bottled, 10L',
        'unit_of_measure' => 'bottle',
    ]);
    $clothing = FniLibraryItem::query()->create([
        'item_category' => 'Non Food Items',
        'item_name' => 'Family Clothing Kit',
        'brand_description' => '',
        'unit_of_measure' => 'kit',
    ]);
    $hygiene = FniLibraryItem::query()->create([
        'item_category' => 'Non Food Items',
        'item_name' => 'Hygiene Kit',
        'brand_description' => '',
        'unit_of_measure' => 'kit',
    ]);
    $kitchen = FniLibraryItem::query()->create([
        'item_category' => 'Non Food Items',
        'item_name' => 'Kitchen Kit',
        'brand_description' => '',
        'unit_of_measure' => 'kit',
    ]);
    $sleeping = FniLibraryItem::query()->create([
        'item_category' => 'Non Food Items',
        'item_name' => 'Sleeping Kit',
        'brand_description' => '',
        'unit_of_measure' => 'kit',
    ]);

    $items = collect([
        (object) ['item_name' => 'Family Food Pack', 'requested_quantity' => 10, 'fni_library_item_id' => $ffp->id],
        (object) ['item_name' => 'Water - Bottled, 10L', 'requested_quantity' => 10, 'fni_library_item_id' => $water->id],
        (object) ['item_name' => 'Family Clothing Kit', 'requested_quantity' => 2, 'fni_library_item_id' => $clothing->id],
        (object) ['item_name' => 'Hygiene Kit', 'requested_quantity' => 2, 'fni_library_item_id' => $hygiene->id],
        (object) ['item_name' => 'Kitchen Kit', 'requested_quantity' => 2, 'fni_library_item_id' => $kitchen->id],
        (object) ['item_name' => 'Sleeping Kit', 'requested_quantity' => 2, 'fni_library_item_id' => $sleeping->id],
    ]);

    $summary = RequestedGoodsTypeSummary::summarize($items);

    expect($summary)->toBe('food and non-food items')
        ->and($summary)->not->toContain('family food packs')
        ->and($summary)->not->toContain('Hygiene Kit')
        ->and($summary)->not->toContain('Kitchen Kit')
        ->and($summary)->not->toContain('Sleeping Kit')
        ->and($summary)->not->toContain('Family Clothing Kit')
        ->and($summary)->not->toContain('Water - Bottled');
});

it('summarizes food-only requests without non-food wording', function (): void {
    $ffp = FniLibraryItem::query()->create([
        'item_category' => 'Family Food Packs',
        'item_name' => 'Family Food Pack',
        'brand_description' => '',
        'unit_of_measure' => 'box',
    ]);
    $water = FniLibraryItem::query()->create([
        'item_category' => 'Food Items',
        'item_name' => 'Water',
        'brand_description' => 'Bottled, 10L',
        'unit_of_measure' => 'bottle',
    ]);

    $summary = RequestedGoodsTypeSummary::summarize(collect([
        (object) ['item_name' => 'Family Food Pack', 'requested_quantity' => 10, 'fni_library_item_id' => $ffp->id],
        (object) ['item_name' => 'Water - Bottled, 10L', 'requested_quantity' => 5, 'fni_library_item_id' => $water->id],
    ]));

    expect($summary)->toBe('food items')
        ->and($summary)->not->toContain('family food packs')
        ->and($summary)->not->toContain('non-food');
});

it('summarizes non-food-only requests without food wording', function (): void {
    $hygiene = FniLibraryItem::query()->create([
        'item_category' => 'Non Food Items',
        'item_name' => 'Hygiene Kit',
        'brand_description' => '',
        'unit_of_measure' => 'kit',
    ]);

    $summary = RequestedGoodsTypeSummary::summarize(collect([
        (object) ['item_name' => 'Hygiene Kit', 'requested_quantity' => 2, 'fni_library_item_id' => $hygiene->id],
    ]));

    expect($summary)->toBe('non-food items')
        ->and($summary)->not->toContain('food and');
});

it('puts brief food / non-food prose in the response letter opening and does not enumerate kit names', function (): void {
    $this->seed(DatabaseSeeder::class);
    User::where('email', 'drrs@example.test')->firstOrFail();

    $ffp = FniLibraryItem::query()->create([
        'item_category' => 'Family Food Packs',
        'item_name' => 'Family Food Pack Type Letter',
        'brand_description' => '',
        'unit_of_measure' => 'box',
    ]);
    $water = FniLibraryItem::query()->create([
        'item_category' => 'Food Items',
        'item_name' => 'Water Type Letter',
        'brand_description' => 'Bottled, 10L',
        'unit_of_measure' => 'bottle',
    ]);
    $hygiene = FniLibraryItem::query()->create([
        'item_category' => 'Non Food Items',
        'item_name' => 'Hygiene Kit Type Letter',
        'brand_description' => '',
        'unit_of_measure' => 'kit',
    ]);
    $kitchen = FniLibraryItem::query()->create([
        'item_category' => 'Non Food Items',
        'item_name' => 'Kitchen Kit Type Letter',
        'brand_description' => '',
        'unit_of_measure' => 'kit',
    ]);
    $sleeping = FniLibraryItem::query()->create([
        'item_category' => 'Non Food Items',
        'item_name' => 'Sleeping Kit Type Letter',
        'brand_description' => '',
        'unit_of_measure' => 'kit',
    ]);

    $request = AssistanceRequest::create([
        'reference_number' => 'REQ-TYPES-LETTER',
        'requesting_agency' => 'Municipality of Tubod',
        'province' => 'SURIGAO DEL NORTE',
        'municipality' => 'TUBOD',
        'requester' => 'Mayor Romarate',
        'requester_position' => 'Municipal Mayor',
        'date_requested' => now()->toDateString(),
        'affected_families' => 2,
        'status' => 'under_review',
        'response_drn' => 'CARAGA-FO-DRMD-DRRMS-SS-REP-26-08-00001-S',
        'assessment_form_data' => [
            'provide_augmentation' => true,
            'response_purpose' => 'Relief Augmentation',
        ],
    ]);

    foreach ([
        [$ffp, 'Family Food Pack', 10, 'box'],
        [$water, 'Water - Bottled, 10L', 10, 'bottle'],
        [$hygiene, 'Hygiene Kit', 2, 'kit'],
        [$kitchen, 'Kitchen Kit', 2, 'kit'],
        [$sleeping, 'Sleeping Kit', 2, 'kit'],
    ] as [$library, $name, $qty, $unit]) {
        $request->items()->create([
            'fni_library_item_id' => $library->id,
            'item_name' => $name,
            'requested_quantity' => $qty,
            'approved_quantity' => $qty,
            'unit' => $unit,
            'priority' => 'normal',
        ]);
    }

    $generated = app(ResponseLetterDocumentService::class)->generate($request->fresh(['items.fniLibraryItem']));
    $zip = new ZipArchive;
    expect($zip->open($generated['path']))->toBeTrue();
    $documentXml = $zip->getFromName('word/document.xml');
    $zip->close();
    @unlink($generated['path']);

    expect($documentXml)->toContain('requesting food and non-food items intended for')
        ->and($documentXml)->not->toContain('requesting family food packs')
        ->and($documentXml)->not->toContain('requesting Family Food Pack, Water - Bottled, 10L')
        ->and($documentXml)->not->toContain('requesting Hygiene Kit')
        ->and($documentXml)->not->toContain('Kitchen Kit, Sleeping Kit')
        ->and($documentXml)->toContain('2 kits of Hygiene Kit');
});

it('renders advance response-letter blade opening with brief food / non-food prose', function (): void {
    $ffp = FniLibraryItem::query()->create([
        'item_category' => 'Family Food Packs',
        'item_name' => 'Family Food Pack',
        'brand_description' => '',
        'unit_of_measure' => 'box',
    ]);
    $hygiene = FniLibraryItem::query()->create([
        'item_category' => 'Non Food Items',
        'item_name' => 'Hygiene Kit',
        'brand_description' => '',
        'unit_of_measure' => 'kit',
    ]);

    $request = AssistanceRequest::create([
        'reference_number' => 'REQ-TYPES-BLADE',
        'requesting_agency' => 'Municipality of Tubod',
        'municipality' => 'TUBOD',
        'requester' => 'Mayor Romarate',
        'date_requested' => now()->toDateString(),
        'affected_families' => 2,
        'status' => 'under_review',
        'response_drn' => 'CARAGA-FO-DRMD-DRRMS-SS-REP-26-08-00002-S',
    ]);
    $request->items()->create([
        'fni_library_item_id' => $ffp->id,
        'item_name' => 'Family Food Pack',
        'requested_quantity' => 10,
        'unit' => 'box',
        'priority' => 'normal',
    ]);
    $request->items()->create([
        'fni_library_item_id' => $hygiene->id,
        'item_name' => 'Hygiene Kit',
        'requested_quantity' => 2,
        'unit' => 'kit',
        'priority' => 'normal',
    ]);

    $html = view('documents.response-letter', [
        'request' => $request->load(['items.fniLibraryItem', 'incident']),
        'advance_copy' => true,
    ])->render();

    expect($html)->toContain('request for food and non-food items intended for')
        ->and($html)->not->toContain('request for family food packs')
        ->and($html)->not->toContain('request for Family Food Pack, Hygiene Kit intended for')
        ->and($html)->toContain('ADVANCE COPY');
});
