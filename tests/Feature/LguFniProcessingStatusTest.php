<?php

use App\Models\AssistanceRequest;
use App\Support\LguFniProcessingStatus;

it('keeps under review when no advance response letter has been issued', function (): void {
    $fni = new AssistanceRequest(['status' => 'under_review', 'reference_number' => 'LGU-REQ-20260803-0QR4S']);

    expect(LguFniProcessingStatus::resolve($fni, false, false))->toBe([
        'key' => LguFniProcessingStatus::KEY_UNDER_REVIEW,
        'label' => 'Under Review',
    ]);
});

it('shows awaiting signed response letter after advance copy is issued', function (): void {
    $fni = new AssistanceRequest([
        'status' => 'under_review',
        'reference_number' => 'LGU-REQ-20260803-0QR4S',
        'lgu_response_letter_advance_sent_at' => now(),
        'lgu_response_letter_advance_acked_at' => now(),
    ]);

    expect(LguFniProcessingStatus::label($fni, true, false))
        ->toBe('Awaiting signed response letter')
        ->and(LguFniProcessingStatus::resolve($fni, true, false)['key'])
        ->toBe(LguFniProcessingStatus::KEY_AWAITING_SIGNED_RESPONSE_LETTER);
});

it('prefers awaiting signed letter even when assessment status is already acted', function (): void {
    $fni = new AssistanceRequest(['status' => 'acted']);

    expect(LguFniProcessingStatus::resolve($fni, true, false))->toBe([
        'key' => LguFniProcessingStatus::KEY_AWAITING_SIGNED_RESPONSE_LETTER,
        'label' => 'Awaiting signed response letter',
    ]);
});

it('shows acted once the signed response letter is available', function (): void {
    $fni = new AssistanceRequest(['status' => 'under_review']);

    expect(LguFniProcessingStatus::resolve($fni, true, true))->toBe([
        'key' => LguFniProcessingStatus::KEY_ACTED,
        'label' => 'Acted',
    ]);
});
