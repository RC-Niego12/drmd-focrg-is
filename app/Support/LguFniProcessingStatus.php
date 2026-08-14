<?php

namespace App\Support;

use App\Models\AssistanceRequest;
use Illuminate\Support\Str;

/**
 * LGU-facing FNI Processing status for the DROMIC / SitRep Requests tab.
 *
 * Ladder:
 * 1. Still under DRRS review (no advance response letter) → Under Review / raw status
 * 2. Advance RL issued (optionally acked), signed copy pending e-PIRMA → Awaiting signed response letter
 * 3. Signed RL available / fully acted → Acted
 */
final class LguFniProcessingStatus
{
    public const KEY_UNDER_REVIEW = 'under_review';

    public const KEY_AWAITING_SIGNED_RESPONSE_LETTER = 'awaiting_signed_response_letter';

    public const KEY_ACTED = 'acted';

    /**
     * @return array{key: string, label: string}
     */
    public static function resolve(?AssistanceRequest $fni, bool $hasAdvance, bool $hasSigned): array
    {
        if (! $fni) {
            return ['key' => '', 'label' => ''];
        }

        if ($hasSigned) {
            return [
                'key' => self::KEY_ACTED,
                'label' => 'Acted',
            ];
        }

        if ($hasAdvance) {
            return [
                'key' => self::KEY_AWAITING_SIGNED_RESPONSE_LETTER,
                'label' => 'Awaiting signed response letter',
            ];
        }

        $status = trim((string) ($fni->status ?? ''));

        return match ($status) {
            'under_review' => [
                'key' => self::KEY_UNDER_REVIEW,
                'label' => 'Under Review',
            ],
            'acted' => [
                'key' => self::KEY_ACTED,
                'label' => 'Acted',
            ],
            'endorsed' => [
                'key' => 'endorsed',
                'label' => 'Endorsed',
            ],
            'submitted' => [
                'key' => 'submitted',
                'label' => 'Submitted',
            ],
            'approved' => [
                'key' => 'approved',
                'label' => 'Approved',
            ],
            'partially_approved' => [
                'key' => 'partially_approved',
                'label' => 'Partially Approved',
            ],
            'rejected' => [
                'key' => 'rejected',
                'label' => 'Rejected',
            ],
            default => [
                'key' => $status,
                'label' => $status !== ''
                    ? Str::title(str_replace('_', ' ', $status))
                    : 'Pending',
            ],
        };
    }

    public static function label(?AssistanceRequest $fni, bool $hasAdvance, bool $hasSigned): string
    {
        return self::resolve($fni, $hasAdvance, $hasSigned)['label'];
    }
}
