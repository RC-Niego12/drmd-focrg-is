<?php

namespace App\Models;

use App\Services\EpirmaDocumentStatusService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class EpirmaSignedDocument extends Model
{
    public const TYPE_ASSESSMENT = 'assessment';

    public const TYPE_RESPONSE_LETTER = 'response_letter';

    public const TYPE_RIS = 'ris';

    public const ACTION_SIGN = 'sign';

    public const ACTION_ROUTE = 'route';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ROUTED = 'routed';

    public const STATUS_PARTIALLY_SIGNED = 'partially_signed';

    public const STATUS_SIGNED = 'signed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const OPEN_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ROUTED,
        self::STATUS_PARTIALLY_SIGNED,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_SIGNED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    /** Statuses that block starting the same type+action again. */
    public const BLOCKING_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ROUTED,
        self::STATUS_PARTIALLY_SIGNED,
        self::STATUS_SIGNED,
    ];

    /**
     * Statuses shown in Track e-PIRMA Status history (Assessment + Response Letter).
     * Excludes cancelled/failed (and any aborted/unavailable) routing attempts.
     */
    public const TRACKABLE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ROUTED,
        self::STATUS_PARTIALLY_SIGNED,
        self::STATUS_SIGNED,
    ];

    protected $fillable = [
        'assistance_request_id',
        'document_type',
        'action',
        'document_uuid',
        'routing_status',
        'signers',
        'remote_document_url',
        'remote_base_path',
        'signature_reference',
        'initiated_by',
        'routed_at',
        'completed_at',
        'last_synced_at',
        'handoff',
        'document_name',
        'document_path',
        'description_subject',
        'encoded_by',
        'timestamp',
    ];

    protected function casts(): array
    {
        return [
            'timestamp' => 'datetime',
            'signers' => 'array',
            'routed_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function assistanceRequest(): BelongsTo
    {
        return $this->belongsTo(AssistanceRequest::class, 'assistance_request_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function isOpen(): bool
    {
        return in_array((string) $this->routing_status, self::OPEN_STATUSES, true);
    }

    public function isSigned(): bool
    {
        return (string) $this->routing_status === self::STATUS_SIGNED;
    }

    public function blocksNewAttempt(): bool
    {
        return in_array((string) $this->routing_status, self::BLOCKING_STATUSES, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toTrackingArray(): array
    {
        $localViewUrl = null;
        if (filled($this->document_path) && Storage::disk('public')->exists($this->document_path)) {
            $localViewUrl = Storage::disk('public')->url($this->document_path);
        }

        $isSigned = $this->isSigned();
        $remote = trim((string) ($this->remote_document_url ?: $this->remote_base_path ?: ''));
        // Prefer usableDocumentToken (base64 public-secure-file OK; skip only if equals APP_KEY).
        $authorizedViewUrl = $remote !== ''
            ? app(EpirmaDocumentStatusService::class)->buildViewUrl($remote)
            : null;

        // Prefer same-origin app proxy when we have a document id (set by controller/API).
        $appViewUrl = $this->id
            ? url("/requests/{$this->assistance_request_id}/epirma/documents/{$this->id}/view")
            : null;

        return [
            'id' => $this->id,
            'document_type' => $this->document_type ?? self::TYPE_ASSESSMENT,
            'action' => $this->action ?? self::ACTION_ROUTE,
            'document_uuid' => $this->document_uuid,
            'document_name' => $this->document_name,
            'routing_status' => $this->routing_status ?? self::STATUS_PENDING,
            'is_signed' => $isSigned,
            'preview_kind' => $isSigned ? 'signed' : 'draft',
            'signers' => is_array($this->signers) ? $this->signers : [],
            'remote_document_url' => $this->remote_document_url,
            'remote_base_path' => $this->remote_base_path,
            'authorized_view_url' => $authorizedViewUrl,
            'local_view_url' => $localViewUrl,
            'app_view_url' => $appViewUrl,
            'signature_reference' => $this->signature_reference,
            'initiated_by' => $this->initiated_by,
            'encoded_by' => $this->encoded_by,
            'handoff' => $this->handoff,
            'routed_at' => $this->routed_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
