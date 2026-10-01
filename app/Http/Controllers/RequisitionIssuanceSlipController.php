<?php

namespace App\Http\Controllers;

use App\Models\AssistanceRequest;
use App\Models\RequisitionIssuanceSlip;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InventoryBalanceService;
use App\Services\RealtimePublisher;
use App\Services\RisDrDocumentPdfService;
use App\Services\RisReservationService;
use App\Services\SSOAuthService;
use App\Services\WorkflowNotificationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class RequisitionIssuanceSlipController extends Controller
{
    public function employees(Request $request, SSOAuthService $sso): JsonResponse
    {
        abort_unless($request->user()?->hasAnyRole(['RROS', 'RROS AA', 'DRRS', 'DRRS AA', 'Super Admin'])
            || $request->user()?->canAny(['manage inventory', 'encode requests']), 403);
        $data = $request->validate([
            'search' => ['required', 'string', 'min:2', 'max:100'],
            'accounting' => ['nullable', 'boolean'],
        ]);
        $accountingOnly = $request->boolean('accounting');

        $directoryError = null;
        try {
            $employees = collect($sso->searchMyPortalEmployees(
                $data['search'],
                $request->session()->get('idp_access_token'),
            ));
        } catch (\Throwable $exception) {
            $directoryError = 'MyPortal is taking longer than expected. Please try the employee search again.';
            $employees = collect();
        }

        if ($employees->isEmpty()) {
            $needle = $data['search'];
            $employees = User::query()
                ->where('is_active', true)
                ->where(function ($query): void {
                    $query->whereNotNull('sso_sub')
                        ->orWhereNotNull('id_number')
                        ->orWhereNotNull('username')
                        ->orWhereNotNull('sso_profile_payload');
                })
                ->where('name', 'like', "%{$needle}%")
                ->limit(30)
                ->get(['name', 'id_number', 'office', 'position', 'designation', 'area_of_assignment', 'contact_number'])
                ->map(function (User $employee): array {
                    return [
                        ...$employee->only(['name', 'id_number', 'office', 'position', 'designation', 'area_of_assignment', 'contact_number']),
                        'section_unit_program' => $employee->office,
                        'division' => null,
                        'mobile_no' => $employee->contact_number,
                    ];
                });
        }

        if ($accountingOnly) {
            $employees = $employees->filter(function ($employee): bool {
                $assignment = collect([
                    data_get($employee, 'office'),
                    data_get($employee, 'area_of_assignment'),
                    data_get($employee, 'designation'),
                    data_get($employee, 'position'),
                    data_get($employee, 'section_unit_program'),
                    data_get($employee, 'division'),
                ])->filter()->implode(' ');

                return str_contains(mb_strtolower($assignment), 'accounting');
            });
        }

        return response()->json([
            'employees' => $employees->take(30)->values()->map(function ($employee): array {
                $assignment = data_get($employee, 'office') ?: data_get($employee, 'area_of_assignment');
                $contact = collect([
                    data_get($employee, 'contact_number'),
                    data_get($employee, 'mobile_no'),
                    data_get($employee, 'mobile'),
                    data_get($employee, 'phone'),
                ])->first(fn ($value) => filled($value));

                return [
                    'value' => (string) data_get($employee, 'name'),
                    'label' => trim((string) data_get($employee, 'name')),
                    'id_number' => (string) data_get($employee, 'id_number'),
                    'position' => (string) data_get($employee, 'position'),
                    'designation' => (string) data_get($employee, 'designation'),
                    'first_name' => (string) data_get($employee, 'first_name'),
                    'middle_name' => (string) data_get($employee, 'middle_name'),
                    'last_name' => (string) data_get($employee, 'last_name'),
                    'section_unit_program' => (string) (data_get($employee, 'section_unit_program') ?: $assignment),
                    'division' => (string) data_get($employee, 'division'),
                    'office' => (string) ($assignment ?: ''),
                    'contact_number' => (string) ($contact ?: ''),
                    'mobile_no' => (string) (data_get($employee, 'mobile_no') ?: $contact ?: ''),
                ];
            }),
            'directory_error' => $employees->isEmpty() ? $directoryError : null,
        ]);
    }

    public function nextNumber(Request $request, AssistanceRequest $assistanceRequest): JsonResponse
    {
        abort_unless($request->user()?->hasAnyRole(['RROS', 'RROS AA', 'Super Admin']), 403);
        $date = Carbon::parse($request->validate(['date' => ['required', 'date']])['date']);
        $existing = $assistanceRequest->requisitionIssuanceSlip;
        if ($existing && $existing->ris_date?->format('Y-m') === $date->format('Y-m')) {
            return response()->json(['ris_number' => $existing->ris_number, 'dr_number' => $existing->dr_number]);
        }

        $risPrefix = 'RIS-CRG-'.$date->format('Y-m').'-';
        $drPrefix = 'DR#-'.$date->format('m').'-';
        $next = RequisitionIssuanceSlip::query()
            ->where('ris_number', 'like', $risPrefix.'%')
            ->pluck('ris_number')
            ->map(fn (string $number): int => preg_match('/(\d{4})$/', $number, $match) ? (int) $match[1] : 0)
            ->max() + 1;
        $counter = str_pad((string) max(1, $next), 4, '0', STR_PAD_LEFT);

        return response()->json(['ris_number' => $risPrefix.$counter, 'dr_number' => $drPrefix.$counter]);
    }

    public function store(Request $request, AssistanceRequest $assistanceRequest, AuditLogger $audit, RealtimePublisher $realtime, InventoryBalanceService $inventoryBalanceService, RisReservationService $reservations, WorkflowNotificationService $notifications): RedirectResponse
    {
        abort_unless($request->user()?->hasAnyRole(['RROS', 'RROS AA', 'Super Admin']), 403);
        abort_unless($assistanceRequest->epirma_assessment_signed_at && $assistanceRequest->epirma_response_letter_signed_at, 422, 'The assessment and response letter must be signed before creating the RIS.');

        $existing = $assistanceRequest->requisitionIssuanceSlip;
        $isDraft = $request->input('status') === 'draft';
        $requiresPostRis = in_array($request->input('status'), ['approved', 'completed'], true);
        $savesEndorsement = $request->input('post_ris_section') === 'endorsement';
        $requiresAccounting = $requiresPostRis && ! $savesEndorsement;
        $routingMode = (string) $request->input('approval_routing_mode', $existing?->approval_routing_mode ?: 'manual');
        $requiresManualDates = ($requiresPostRis || $savesEndorsement) && $routingMode === 'manual';
        $forwardedToAccounting = $request->input('tracking_data.forwarded_to_accounting') === 'Yes';
        $risCreatedDate = $existing?->created_at
            ? $existing->created_at->timezone(config('app.timezone', 'Asia/Manila'))->toDateString()
            : (string) $request->input('ris_date');
        $readableRisCreatedDate = Carbon::parse($risCreatedDate)->format('F j, Y');
        $postRisDateRule = function (string $label, ?string $afterField = null) use ($request, $risCreatedDate, $readableRisCreatedDate): \Closure {
            return function (string $attribute, mixed $value, \Closure $fail) use ($request, $label, $afterField, $risCreatedDate, $readableRisCreatedDate): void {
                if (blank($value)) {
                    return;
                }
                try {
                    $date = Carbon::parse($value)->startOfDay();
                    $created = Carbon::parse($risCreatedDate)->startOfDay();
                } catch (\Throwable) {
                    return;
                }
                if ($date->lt($created)) {
                    $fail("The {$label} date cannot be earlier than the RIS / DR system creation date ({$readableRisCreatedDate}).");

                    return;
                }
                if ($date->gt(now()->startOfDay())) {
                    $fail("The {$label} date cannot be in the future.");

                    return;
                }
                $afterValue = $afterField ? data_get($request->all(), $afterField) : null;
                if (filled($afterValue)) {
                    try {
                        $afterDate = Carbon::parse($afterValue)->startOfDay();
                    } catch (\Throwable) {
                        return;
                    }
                    if ($date->lt($afterDate)) {
                        $fail("The {$label} date cannot be earlier than ".Carbon::parse($afterValue)->format('F j, Y').'.');
                    }
                }
            };
        };
        // Transport / vehicle logistics are owned by Dispatch Plan encoding.
        // Ignore any leftover RIS-form payload so create/update never writes those columns.
        $trackingInput = $request->input('tracking_data', []);
        if (is_array($trackingInput)) {
            foreach ([
                'mode_of_transportation', 'vehicle_type', 'vehicle_types',
                'number_of_vehicles', 'no_of_vehicles',
                'driver_name', 'driver_contact_number', 'vehicle_plate_number',
            ] as $dispatchOwnedKey) {
                unset($trackingInput[$dispatchOwnedKey]);
            }
            $request->merge(['tracking_data' => $trackingInput]);
        }
        $data = $request->validate([
            'ris_number' => ['required', 'string', 'max:100', Rule::unique('requisition_issuance_slips', 'ris_number')->ignore($existing?->id)],
            'ris_date' => ['required', 'date'],
            'purpose_of_release' => [$isDraft ? 'nullable' : 'required', 'string', 'max:255'],
            'recipient' => [$isDraft ? 'nullable' : 'required', 'string', 'max:255'],
            'delivery_site' => [$isDraft ? 'nullable' : 'required', 'string', 'max:255'],
            'receiving_representative' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:80'],
            'remarks' => [$requiresAccounting ? 'required' : 'nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(['draft', 'prepared', 'approved', 'completed'])],
            'post_ris_section' => ['nullable', Rule::in(['endorsement'])],
            'approval_routing_mode' => ['nullable', Rule::in(['manual', 'epirma'])],
            'items' => [$isDraft ? 'nullable' : 'required', 'array', $isDraft ? 'nullable' : 'min:1'],
            'items.*.request_item_id' => ['nullable', 'integer', Rule::exists('request_items', 'id')->where('request_id', $assistanceRequest->id)],
            'items.*.item_name' => [$isDraft ? 'nullable' : 'required', 'string', 'max:255'],
            'items.*.brand_description' => ['nullable', 'string', 'max:1000'],
            'items.*.expiry' => ['nullable', 'string', 'max:1000'],
            'items.*.unit' => ['nullable', 'string', 'max:80'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'items.*.warehouse_name' => ['nullable', 'string', 'max:255'],
            'items.*.warehouse_type' => ['nullable', 'string', 'max:255'],
            'items.*.allocation_guide' => ['nullable', 'numeric'],
            'items.*.wit_stock_balance' => ['nullable', 'numeric'],
            'items.*.remaining_balance' => ['nullable', 'numeric'],
            'items.*.allocation_status' => ['nullable', 'string', 'max:80'],
            'items.*.remarks' => ['nullable', 'string', 'max:1000'],
            'items.*.quantity' => [$isDraft ? 'nullable' : 'required', 'integer', $isDraft ? 'min:0' : 'min:1'],
            'tracking_data' => [$isDraft ? 'nullable' : 'required', 'array'],
            'tracking_data.assessment_drn_for_ris' => [$isDraft ? 'nullable' : 'required', 'string', 'max:255'],
            'tracking_data.purpose_of_request' => [$isDraft ? 'nullable' : 'required', 'string', 'max:255'],
            'tracking_data.incident_type' => ['nullable', 'string', 'max:255'],
            'tracking_data.incident_specification' => ['nullable', 'string', 'max:255'],
            'tracking_data.dr_number' => [$isDraft ? 'nullable' : 'required', 'string', 'max:100'],
            'tracking_data.ris_drn' => [
                Rule::requiredIf(fn (): bool => $request->input('status') === 'prepared'),
                'nullable', 'string', 'max:255',
                function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                    if (blank($value)) {
                        return;
                    }
                    try {
                        $date = Carbon::parse($request->input('ris_date'));
                    } catch (\Throwable) {
                        $fail('A valid RIS preparation date is required.');

                        return;
                    }
                    $prefix = 'CARAGA-FO-DRMD-RROS-A-REQ-'.$date->format('y-m').'-';
                    $suffix = str_starts_with((string) $value, $prefix) ? substr((string) $value, strlen($prefix)) : '';
                    if (! preg_match('/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$/', $suffix)) {
                        $fail("The RIS DRN must begin with {$prefix} and include a complete sequence after it.");
                    }
                },
            ],
            'tracking_data.ardo_endorsed_at' => [
                $requiresManualDates ? 'required' : 'nullable',
                'date',
                $postRisDateRule('endorsement'),
            ],
            'tracking_data.ardo_returned_at' => [
                $requiresManualDates ? 'required' : 'nullable',
                'date',
                $postRisDateRule('return', 'tracking_data.ardo_endorsed_at'),
            ],
            'tracking_data.delivered_at' => ['nullable', 'date'],
            'tracking_data.release_witnessed_by' => ['nullable', 'string', 'max:255'],
            'tracking_data.received_by' => ['nullable', 'string', 'max:255'],
            'tracking_data.date_received' => ['nullable', 'date'],
            'tracking_data.fully_delivered' => ['nullable', Rule::in(['Yes', 'No'])],
            'tracking_data.has_returned_items' => ['nullable', Rule::in(['Yes', 'No'])],
            'tracking_data.returned_particulars' => ['nullable', 'string', 'max:1000'],
            'tracking_data.returned_quantity' => ['nullable', 'integer', 'min:0'],
            'tracking_data.returned_reason' => ['nullable', 'string', 'max:2000'],
            'tracking_data.forwarded_to_accounting' => [$requiresAccounting ? 'required' : 'nullable', Rule::in(['Yes', 'No'])],
            'tracking_data.forwarded_to_accounting_at' => [
                Rule::requiredIf(fn (): bool => $requiresAccounting && $forwardedToAccounting),
                'nullable',
                'date',
                $postRisDateRule('accounting forwarding', 'tracking_data.ardo_returned_at'),
            ],
            'tracking_data.accounting_received_by' => [Rule::requiredIf(fn (): bool => $requiresAccounting && $forwardedToAccounting), 'nullable', 'string', 'max:255'],
            'tracking_data.assessment_link' => ['nullable', 'string', 'max:2000'],
            'tracking_data.ris_link' => ['nullable', 'string', 'max:2000'],
            'tracking_data.rds_link' => ['nullable', 'string', 'max:2000'],
            'tracking_data.csmr_link' => ['nullable', 'string', 'max:2000'],
            'ris_dr_file' => ['nullable', 'file', 'mimes:pdf', 'max:20480'],
            'rds_file' => ['nullable', 'file', 'mimes:pdf', 'max:20480'],
            'csmr_file' => ['nullable', 'file', 'mimes:pdf', 'max:20480'],
        ]);
        $data['items'] = collect($data['items'] ?? [])
            ->filter(fn (array $item): bool => filled($item['item_name'] ?? null))
            ->map(function (array $item) use ($isDraft): array {
                if ($isDraft && ! array_key_exists('quantity', $item)) {
                    $item['quantity'] = 0;
                }
                $item['quantity'] = (int) ($item['quantity'] ?? 0);

                return $item;
            })
            ->values()
            ->all();
        $data['tracking_data'] = $data['tracking_data'] ?? [];
        $data['purpose_of_release'] = $data['purpose_of_release'] ?? '';
        $data['recipient'] = $data['recipient'] ?? '';

        $submittedRisDrn = data_get($data, 'tracking_data.ris_drn');
        $canAssignRisDrn = $request->user()?->hasAnyRole(['RROS', 'RROS AA', 'Super Admin'])
            || $request->user()?->can('assign ris drn');
        abort_if(
            filled($submittedRisDrn) && ! $canAssignRisDrn && $submittedRisDrn !== $existing?->ris_drn,
            403,
            'Only RROS or RROS AA can assign or change the RIS DRN.'
        );

        $uploadedDocuments = [];
        foreach (['ris_dr', 'rds', 'csmr'] as $kind) {
            if (! $request->hasFile("{$kind}_file")) {
                continue;
            }
            $file = $request->file("{$kind}_file");
            $name = $file->getClientOriginalName();
            $path = $file->storeAs("ris-documents/{$assistanceRequest->id}", "{$kind}-".Str::uuid().'.pdf', 'public');
            $oldPath = $existing?->{"{$kind}_path"};
            if ($oldPath && $oldPath !== $path) {
                Storage::disk('public')->delete($oldPath);
            }
            $uploadedDocuments["{$kind}_path"] = $path;
            $uploadedDocuments["{$kind}_name"] = $name;
        }

        $allowedItems = $assistanceRequest->items()->get()->keyBy('id');
        $allocationGroups = collect($data['items'])->groupBy(fn (array $item): string => (string) ($item['request_item_id'] ?? strtolower($item['item_name'])));
        foreach ($allocationGroups as $items) {
            $first = $items->first();
            $source = $allowedItems->get($first['request_item_id'] ?? null)
                ?: $allowedItems->firstWhere('item_name', $first['item_name']);
            $provision = $source ? (float) ($source->approved_quantity ?: $source->requested_quantity) : null;
            $allocated = (float) $items->sum('quantity');
            if ($data['status'] === 'prepared' && $source) {
                abort_unless(
                    $allocated === $provision,
                    422,
                    "The combined allocation for {$first['item_name']} must equal the approved assessment provision of {$provision}."
                );
            } else {
                abort_unless(! $source || $allocated <= $provision, 422, 'The combined RIS allocations exceed the approved request quantity.');
            }
        }
        if ($data['status'] === 'prepared') {
            abort_if(collect($data['items'])->contains(fn (array $item): bool => blank($item['warehouse_id'] ?? null)), 422, 'Every prepared RIS allocation must have a source warehouse.');
        }
        if ($data['status'] === 'approved') {
            if (! $savesEndorsement) {
                abort_unless(
                    ($existing?->approval_routing_mode === 'epirma' && $existing?->ris_epirma_status === 'signed')
                        || (filled($existing?->ardo_endorsed_at) && filled($existing?->ardo_returned_at)),
                    422,
                    'Complete manual approval or e-PIRMA signing in section 3 before completing section 4.'
                );
            }
            abort_unless(
                $existing && in_array($existing->status, ['prepared', 'approved'], true),
                422,
                'Post RIS / DR updates require a generated (prepared) RIS / DR first.'
            );
        }
        if ($data['status'] === 'completed') {
            abort_unless(
                $existing && in_array($existing->status, ['approved', 'completed'], true),
                422,
                'A RIS / DR can only be completed after the post RIS / DR form is approved.'
            );
            abort_unless(
                ($request->hasFile('ris_dr_file') || filled($existing?->ris_dr_path))
                && ($request->hasFile('rds_file') || filled($existing?->rds_path) || filled($existing?->rds_link) || filled(data_get($request->input('tracking_data'), 'rds_link')))
                && ($request->hasFile('csmr_file') || filled($existing?->csmr_path) || filled($existing?->csmr_link) || filled(data_get($request->input('tracking_data'), 'csmr_link'))),
                422,
                'Upload and confirm the signed RIS / DR, RDS, and CSMR before marking this record Completed.'
            );
        }
        $availableByWarehouseItem = $inventoryBalanceService->balanceRows()
            ->groupBy(fn (array $row): string => ($row['warehouse_id'] ?? '').'|'.preg_replace('/[^a-z0-9]+/', '', strtolower((string) ($row['item'] ?? ''))))
            ->map(fn ($rows): float => $rows->sum(fn (array $row): float => max(0, (float) ($row['available_balance'] ?? 0))));
        $availableByBatch = $inventoryBalanceService->balanceRows()
            ->groupBy(fn (array $row): string => implode('|', [
                $row['warehouse_id'] ?? '',
                preg_replace('/[^a-z0-9]+/', '', strtolower((string) ($row['item'] ?? ''))),
                strtolower(trim((string) ($row['brand_description'] ?? '-'))),
                strtolower(trim((string) ($row['expiry'] ?? 'N/A'))),
            ]))
            ->map(fn ($rows): float => $rows->sum(fn (array $row): float => max(0, (float) ($row['available_balance'] ?? 0))));
        $before = $existing?->toArray() ?? [];
        $ris = Cache::lock('ris-planning-reservations', 30)->block(10, function () use ($assistanceRequest, $data, $request, $uploadedDocuments, $availableByWarehouseItem, $availableByBatch, $reservations, $existing): RequisitionIssuanceSlip {
            return DB::transaction(function () use ($assistanceRequest, $data, $request, $uploadedDocuments, $availableByWarehouseItem, $availableByBatch, $reservations, $existing): RequisitionIssuanceSlip {
                $reservedByOthers = $reservations->totals($existing?->id);
                collect($data['items'])->filter(fn (array $item): bool => filled($item['warehouse_id'] ?? null))
                    ->groupBy(fn (array $item): string => $item['warehouse_id'].'|'.preg_replace('/[^a-z0-9]+/', '', strtolower($item['item_name'])))
                    ->each(function ($items, string $key) use ($availableByWarehouseItem, $reservedByOthers): void {
                        $physical = (float) $availableByWarehouseItem->get($key, 0);
                        $reserved = (float) $reservedByOthers->get($key, 0);
                        abort_if(
                            $items->sum('quantity') > max(0, $physical - $reserved),
                            422,
                            'This allocation exceeds stock available for planning because another active RIS already reserved part of the physical balance. Reapply the suggested plan.'
                        );
                    });
                if ($data['status'] === 'prepared') {
                    $reservedBatches = $reservations->batchTotals($existing?->id);
                    collect($data['items'])
                        ->groupBy(fn (array $item): string => implode('|', [
                            $item['warehouse_id'],
                            preg_replace('/[^a-z0-9]+/', '', strtolower($item['item_name'])),
                            strtolower(trim((string) (($item['brand_description'] ?? null) ?: '-'))),
                            strtolower(trim((string) (($item['expiry'] ?? null) ?: 'N/A'))),
                        ]))
                        ->each(function ($items, string $key) use ($availableByBatch, $reservedBatches): void {
                            abort_if(
                                $items->sum('quantity') > max(0, (float) $availableByBatch->get($key, 0) - (float) $reservedBatches->get($key, 0)),
                                422,
                                'The selected brand/description and expiry do not have enough stock available. Reapply the suggested plan.'
                            );
                        });
                }

                $tracking = $data['tracking_data'];
                $existingTracking = is_array($existing?->tracking_data) ? $existing->tracking_data : [];
                // Preserve non-transport delivery/receipt legacy fields when the post form omits them.
                foreach ([
                    'received_by', 'date_received',
                    'delivered_at', 'release_witnessed_by', 'fully_delivered', 'has_returned_items',
                    'returned_particulars', 'returned_quantity', 'returned_reason',
                ] as $legacyKey) {
                    if (blank($tracking[$legacyKey] ?? null) && filled($existingTracking[$legacyKey] ?? null)) {
                        $tracking[$legacyKey] = $existingTracking[$legacyKey];
                    }
                }
                if (blank($tracking['received_by'] ?? null) && filled($existing?->received_by)) {
                    $tracking['received_by'] = $existing->received_by;
                }
                if (blank($tracking['delivered_at'] ?? null) && filled($existing?->delivered_at)) {
                    $tracking['delivered_at'] = optional($existing->delivered_at)->format('Y-m-d');
                }
                if (blank($tracking['release_witnessed_by'] ?? null) && filled($existing?->release_witnessed_by)) {
                    $tracking['release_witnessed_by'] = $existing->release_witnessed_by;
                }
                if (blank($tracking['fully_delivered'] ?? null) && $existing?->fully_delivered !== null) {
                    $tracking['fully_delivered'] = $existing->fully_delivered ? 'Yes' : 'No';
                }
                if (blank($tracking['has_returned_items'] ?? null) && $existing?->has_returned_items !== null) {
                    $tracking['has_returned_items'] = $existing->has_returned_items ? 'Yes' : 'No';
                }
                if (blank($tracking['returned_particulars'] ?? null) && filled($existing?->returned_particulars)) {
                    $tracking['returned_particulars'] = $existing->returned_particulars;
                }
                if (blank($tracking['returned_quantity'] ?? null) && $existing?->returned_quantity !== null) {
                    $tracking['returned_quantity'] = $existing->returned_quantity;
                }
                if (blank($tracking['returned_reason'] ?? null) && filled($existing?->returned_reason)) {
                    $tracking['returned_reason'] = $existing->returned_reason;
                }
                // Never mass-assign dispatch-owned transport columns from the RIS form.
                $normalized = collect($tracking)->only([
                    'assessment_drn_for_ris', 'purpose_of_request', 'incident_type', 'incident_specification',
                    'dr_number', 'ris_drn', 'ardo_endorsed_at', 'ardo_returned_at', 'delivered_at',
                    'release_witnessed_by',
                    'received_by', 'date_received', 'returned_particulars', 'returned_quantity', 'returned_reason',
                    'forwarded_to_accounting_at', 'accounting_received_by', 'assessment_link', 'ris_link', 'rds_link', 'csmr_link',
                ])->all();
                foreach (['fully_delivered', 'has_returned_items', 'forwarded_to_accounting'] as $field) {
                    $raw = $tracking[$field] ?? null;
                    if (blank($raw) && $raw !== false && $raw !== 0 && $raw !== '0') {
                        $normalized[$field] = null;
                    } elseif ($raw === true || $raw === 'Yes' || $raw === 1 || $raw === '1') {
                        $normalized[$field] = true;
                    } elseif ($raw === false || $raw === 'No' || $raw === 0 || $raw === '0') {
                        $normalized[$field] = false;
                    } else {
                        $normalized[$field] = null;
                    }
                }

                // Keep any pre-existing tracking transport keys for legacy sheet sync, but never
                // refresh them from the RIS create/update payload (Dispatch Plan owns writes).
                $trackingData = $tracking;
                foreach ([
                    'mode_of_transportation', 'vehicle_type', 'vehicle_types',
                    'number_of_vehicles', 'no_of_vehicles',
                    'driver_name', 'driver_contact_number', 'vehicle_plate_number',
                ] as $transportKey) {
                    if (array_key_exists($transportKey, $existingTracking)) {
                        $trackingData[$transportKey] = $existingTracking[$transportKey];
                    } else {
                        unset($trackingData[$transportKey]);
                    }
                }

                $slip = RequisitionIssuanceSlip::updateOrCreate(
                    ['request_id' => $assistanceRequest->id],
                    [
                        ...collect($data)->except(['items', 'tracking_data', 'ris_dr_file', 'rds_file', 'csmr_file', 'post_ris_section'])->all(),
                        ...$normalized,
                        ...$uploadedDocuments,
                        'tracking_data' => $trackingData,
                        'items' => $data['items'],
                        'prepared_by' => $request->user()->id,
                        'sync_source' => 'system',
                        'reservation_status' => $existing?->reservation_status ?? 'active',
                    ]
                );
                $slip->allocationItems()->delete();
                $slip->allocationItems()->createMany(collect($data['items'])->map(fn (array $item): array => [
                    'request_item_id' => $item['request_item_id'] ?? null, 'warehouse_id' => $item['warehouse_id'] ?? null,
                    'unit' => $item['unit'] ?? null, 'item_name' => $item['item_name'], 'quantity' => (int) ($item['quantity'] ?? 0),
                    'unit_cost' => isset($item['unit_cost']) && $item['unit_cost'] !== '' ? round((float) $item['unit_cost'], 2) : null,
                    'brand_description' => $item['brand_description'] ?? null,
                    'expiry' => $item['expiry'] ?? null,
                    'warehouse_name' => $item['warehouse_name'] ?? null,
                    'warehouse_type' => $item['warehouse_type'] ?? null,
                    'allocation_guide' => $item['allocation_guide'] ?? null,
                    'wit_stock_balance' => $item['wit_stock_balance'] ?? null,
                    'remaining_balance' => $item['remaining_balance'] ?? null,
                    'allocation_status' => $item['allocation_status'] ?? null,
                    'remarks' => $item['remarks'] ?? null,
                ])->all());

                return $slip;
            });
        });

        $audit->log($existing ? 'ris.updated' : 'ris.created', $ris, $before, $ris->fresh()->toArray());
        $becamePrepared = $data['status'] === 'prepared'
            && (! $existing || ($existing->status ?? null) !== 'prepared');
        $becameApproved = $data['status'] === 'approved'
            && (! $existing || ($existing->status ?? null) !== 'approved');
        if (! $existing) {
            $notifications->notifyRisPostMonitoringAssigned($ris, $assistanceRequest);
        }
        if ($becamePrepared) {
            $notifications->notifyRisReadyForSigning($ris, $assistanceRequest);
        }
        $dispatchStaffIds = User::permission('manage dispatches')->where('is_active', true)->pluck('id');
        if ($dispatchStaffIds->isEmpty()) {
            $dispatchStaffIds = User::role(['RROS', 'Super Admin'])->where('is_active', true)->pluck('id');
        }
        $realtime->usersChanged(
            $dispatchStaffIds->merge(User::role(['RROS', 'RROS AA'])->pluck('id'))->unique()->values(),
            $becamePrepared ? 'ris.prepared' : ($becameApproved ? 'ris.approved' : 'ris.updated'),
            [
                'request_id' => $assistanceRequest->id,
                'ris_id' => $ris->id,
                'next_process' => $becamePrepared ? 'ris_signing' : ($becameApproved ? 'dispatch_ready' : null),
            ]
        );
        if ($becameApproved) {
            $realtime->usersChanged(
                $dispatchStaffIds->merge(User::role(['RROS', 'RROS AA'])->pluck('id'))->unique()->values(),
                'dispatch.ready.changed',
                [
                    'request_id' => $assistanceRequest->id,
                    'ris_id' => $ris->id,
                    'ris_status' => $ris->status,
                    'approval_mode' => $ris->approval_routing_mode,
                    'ready' => true,
                ]
            );
        }

        $successMessage = match ($data['status']) {
            'draft' => 'Draft saved',
            'approved' => $becameApproved
                ? 'Post RIS / DR update saved. Delivery and accounting details recorded.'
                : 'Post RIS / DR update saved.',
            'completed' => 'RIS / DR marked completed.',
            default => $becamePrepared
                ? 'RIS / DR generated successfully. Please notify the dispatch officer that an RIS is ready for signing.'
                : ($existing ? 'RIS / DR updated successfully.' : 'RIS / DR created successfully.'),
        };

        return back()->with('success', $successMessage);
    }

    public function document(Request $request, RequisitionIssuanceSlip $slip, string $kind)
    {
        abort_unless($request->user()?->hasAnyRole(['RROS', 'RROS AA', 'Super Admin', 'DRRS']), 403);
        abort_unless(in_array($kind, ['ris', 'rds', 'csmr'], true), 404);
        abort_if(
            ($slip->status ?? 'draft') === 'draft',
            403,
            'Draft RIS / DR documents cannot be printed or downloaded. Generate (prepare) the RIS / DR first.',
        );
        // Print/preview HTML is client-side; uploaded files are available after a prepared save.
        if ($kind === 'ris' && filled($slip->ris_link)) {
            return redirect()->away($slip->ris_link);
        }
        $path = $slip->{"{$kind}_path"};
        if ($path && Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->response($path, $slip->{"{$kind}_name"} ?: strtoupper($kind).'.pdf', ['Content-Type' => 'application/pdf']);
        }
        $legacyLink = $slip->{"{$kind}_link"};
        if (filled($legacyLink)) {
            return redirect()->away($legacyLink);
        }
        abort(404, strtoupper($kind).' document is not available.');
    }

    /**
     * Advance DomPDF preview for RIS / DR (same iframe pattern as Assessment draft PDF).
     * Available for draft and prepared slips; not a signed/uploaded file.
     */
    public function previewPdf(Request $request, RequisitionIssuanceSlip $slip, string $kind, RisDrDocumentPdfService $pdf): HttpResponse
    {
        abort_unless($request->user()?->hasAnyRole(['RROS', 'RROS AA', 'Super Admin', 'DRRS']), 403);

        return $pdf->streamAdvancePreview($slip, $kind, $request->boolean('inline', true));
    }

    public function updateDrn(Request $request, RequisitionIssuanceSlip $slip, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()?->hasAnyRole(['RROS', 'RROS AA', 'Super Admin']) || $request->user()?->can('assign ris drn'), 403);
        $prefix = 'CARAGA-FO-DRMD-RROS-A-REQ-'.$slip->ris_date->format('y-m').'-';
        $data = $request->validate([
            'ris_drn' => ['required', 'string', 'max:255', 'regex:/^'.preg_quote($prefix, '/').'[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$/'],
        ], ['ris_drn.regex' => "The RIS DRN must begin with {$prefix} and include a complete final reference segment."]);
        $before = ['ris_drn' => $slip->ris_drn];
        $tracking = $slip->tracking_data ?: [];
        $tracking['ris_drn'] = $data['ris_drn'];
        $slip->forceFill(['ris_drn' => $data['ris_drn'], 'tracking_data' => $tracking])->save();
        $audit->log('ris.drn_assigned_by_rros_aa', $slip, $before, $data, $request->user()->id);

        return response()->json(['success' => true, 'ris_drn' => $data['ris_drn']]);
    }

    public function cancel(Request $request, RequisitionIssuanceSlip $slip, AuditLogger $audit, RealtimePublisher $realtime): RedirectResponse
    {
        abort_unless($request->user()?->hasAnyRole(['RROS', 'RROS AA', 'Super Admin']), 403);
        $before = $slip->toArray();
        $updates = ['reservation_status' => 'released'];
        // Releasing after post approval closes the RIS into Completed.
        if (($slip->status ?? null) === 'approved') {
            abort_unless(
                $slip->hasCompletePostRisData(),
                422,
                'Complete all required Post RIS / DR fields before confirming WIT issuance and moving to Completed.'
            );
            $updates['status'] = 'completed';
        }
        $slip->update($updates);
        $audit->log('ris.reservation_released', $slip, $before, $slip->fresh()->toArray());
        $realtime->usersChanged(User::role(['RROS', 'RROS AA'])->pluck('id'), 'ris.updated', [
            'request_id' => $slip->request_id,
            'ris_id' => $slip->id,
        ]);

        return back()->with('success', 'RIS planning reservation released. Its quantities are available for other allocation plans again.');
    }
}
