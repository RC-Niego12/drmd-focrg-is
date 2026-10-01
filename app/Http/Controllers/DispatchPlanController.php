<?php

namespace App\Http\Controllers;

use App\Models\AssistanceRequest;
use App\Models\DispatchPlan;
use App\Models\DispatchPlanItem;
use App\Models\DispatchDeliveryUpdate;
use App\Models\LguDirectoryEntry;
use App\Models\LguDirectoryLswdoAlternate;
use App\Models\LguDirectoryOfficial;
use App\Models\OperationalLibraryValue;
use App\Models\RequisitionIssuanceSlip;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\WorkflowNotification;
use App\Services\AuditLogger;
use App\Services\DispatchInventoryIssuanceService;
use App\Services\DispatchInventoryReturnService;
use App\Services\DeliveryEvidencePhotoService;
use App\Services\InventoryBalanceService;
use App\Services\RealtimePublisher;
use App\Services\RisDrDocumentPdfService;
use App\Services\RisReservationService;
use App\Services\WorkflowNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class DispatchPlanController extends Controller
{
    public function reverseLocation(Request $request): JsonResponse
    {
        abort_unless($request->user() && $this->isDswdEmployee($request->user()), 403);
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'detailed' => ['sometimes', 'boolean'],
        ]);
        $detailed = (bool) ($data['detailed'] ?? false);
        $fallback = number_format((float) $data['latitude'], 6).', '.number_format((float) $data['longitude'], 6);
        $cacheKey = sprintf('dispatch:reverse-location:v2:%s:%.4f:%.4f', $detailed ? 'detailed' : 'quick', (float) $data['latitude'], (float) $data['longitude']);

        $resolved = Cache::remember($cacheKey, now()->addDays(30), function () use ($data, $fallback, $detailed): array {
          try {
            $geoapifyKey = trim((string) config('services.geoapify.api_key'));
            if ($geoapifyKey !== '') {
                $geoapify = Http::connectTimeout(1)->timeout(2)->get('https://api.geoapify.com/v1/geocode/reverse', [
                    'lat' => $data['latitude'],
                    'lon' => $data['longitude'],
                    'format' => 'json',
                    'apiKey' => $geoapifyKey,
                ])->json();
                $geoapifyAddress = trim((string) data_get($geoapify, 'results.0.formatted', ''));
                if ($geoapifyAddress !== '') {
                    return ['location' => $geoapifyAddress, 'provider' => 'geoapify'];
                }
            }

            // This endpoint is optimized for browser/mobile coordinate lookup
            // and requires no API key, making it a quick fallback in development.
            if (! $detailed) {
                $bigData = Http::connectTimeout(1)->timeout(2)->get('https://api.bigdatacloud.net/data/reverse-geocode-client', [
                    'latitude' => $data['latitude'],
                    'longitude' => $data['longitude'],
                    'localityLanguage' => 'en',
                ])->json();
                $cleanPart = static fn ($part): string => trim((string) preg_replace('/\s*\((?:Region [^)]+|the)\)\s*/i', '', (string) $part));
                $bigDataAddress = collect([
                    data_get($bigData, 'locality'),
                    data_get($bigData, 'city'),
                    data_get($bigData, 'principalSubdivision'),
                    data_get($bigData, 'postcode'),
                    data_get($bigData, 'countryName'),
                ])->map($cleanPart)->filter()->unique()->implode(', ');
                if ($bigDataAddress !== '') {
                    return ['location' => $bigDataAddress, 'provider' => 'bigdatacloud'];
                }
            }

            $result = Http::withHeaders(['User-Agent' => 'DROMIS-FO-Caraga/1.0'])
                ->connectTimeout(1)->timeout($detailed ? 5 : 2)->get('https://nominatim.openstreetmap.org/reverse', ['format' => 'jsonv2', 'lat' => $data['latitude'], 'lon' => $data['longitude'], 'zoom' => 18, 'addressdetails' => 1])->json();
            $address = trim((string) ($result['display_name'] ?? ''));
            return ['location' => $address ?: $fallback, 'provider' => $address !== '' ? 'openstreetmap' : 'coordinates'];
          } catch (\Throwable) {
            return ['location' => $fallback, 'provider' => 'coordinates'];
          }
        });

        // Coordinate-only failures are deliberately not retained so a retry can
        // resolve the readable address as soon as connectivity improves.
        if (($resolved['provider'] ?? null) === 'coordinates') Cache::forget($cacheKey);

        return response()->json($resolved);
    }
    public function storeDeliveryUpdate(Request $request, DispatchPlan $dispatch, AuditLogger $audit, RealtimePublisher $realtime, DeliveryEvidencePhotoService $evidencePhotos): RedirectResponse
    {
        $this->authorizeDispatchAccess($request, $dispatch);
        abort_unless(
            $request->user() && ($this->deliveryUpdateDevelopmentAccess() || $this->isAssignedEscort($dispatch, $request->user())),
            403,
            'Only the assigned escort may record GPS and delivery field updates through the Delivery Escort Workspace.'
        );
        abort_unless(in_array($dispatch->status, [DispatchPlan::STATUS_RELEASED, DispatchPlan::STATUS_IN_TRANSIT], true), 422, 'Delivery updates are available after release and while the delivery is in transit.');
        $data = $request->validate([
            'vehicle_index' => ['required', 'integer', 'min:0'],
            'stage' => ['required', Rule::in([
                'departed', 'arrived', 'unloading_started',
                'unloading_completed', 'delay', 'incident', 'checkpoint',
            ])],
            'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'location' => ['required', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy_meters' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'message' => ['required', 'string', 'min:10', 'max:3000'],
            'photos' => ['required', 'array', 'min:1', 'max:6'],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);
        abort_unless(isset($dispatch->resolvedVehicleDetails()[(int) $data['vehicle_index']]), 422, 'Select a valid vehicle.');
        $this->authorizeVehicleAccess($request->user(), $dispatch, (int) $data['vehicle_index']);
        $this->assertDeliveryUpdateSequence($dispatch, $data);
        $paths = [];
        try {
            foreach ($request->file('photos', []) as $photo) {
                $paths[] = $evidencePhotos->store(
                    $photo,
                    "dispatch-delivery-updates/{$dispatch->id}",
                    Arr::only($data, ['stage', 'occurred_at', 'location', 'latitude', 'longitude']),
                );
            }
        } catch (\RuntimeException $exception) {
            foreach ($paths as $path) Storage::disk('local')->delete($path);
            throw ValidationException::withMessages(['photos' => $exception->getMessage()]);
        }
        $update = DB::transaction(function () use ($request, $dispatch, $data, $paths): DispatchDeliveryUpdate {
            $lockedDispatch = DispatchPlan::query()->lockForUpdate()->findOrFail($dispatch->id);
            $update = $lockedDispatch->deliveryUpdates()->create([
                ...Arr::except($data, ['photos']),
                'reported_by' => $request->user()->id,
                'reporter_role' => $this->deliveryUpdateReporterRole($request->user(), $lockedDispatch),
                'photo_paths' => $paths,
            ]);

            // A posted departure update and Mark In Transit describe the same
            // event. Persist them together so the progress tracker, stage
            // button, and field timeline can never disagree.
            if ($data['stage'] === 'departed') {
                $vehicles = array_values($lockedDispatch->resolvedVehicleDetails());
                $vehicleIndex = (int) $data['vehicle_index'];
                if (isset($vehicles[$vehicleIndex])) {
                    $vehicles[$vehicleIndex]['departed_at'] = Carbon::parse($data['occurred_at'])->format('Y-m-d H:i:s');
                    $payload = [
                        ...$lockedDispatch->toArray(),
                        'vehicle_details' => $vehicles,
                    ];
                    $lockedDispatch->forceFill([
                        'vehicle_details' => $vehicles,
                        'status' => $this->deriveDispatchStatusFromTransactions($payload),
                    ])->save();
                }
            }

            return $update;
        });
        $dispatch->refresh();
        $audit->log('dispatch.delivery_update.created', $update, [], $update->toArray());
        $this->publishDeliveryUpdateRealtime($realtime, $dispatch);
        $this->notifyDeliveryUpdateStakeholders($request->user(), $dispatch, $update);

        return back()->with('success', $data['stage'] === 'departed'
            ? 'Departure update posted and Vehicle '.((int) $data['vehicle_index'] + 1).' marked In Transit.'
            : 'Delivery update posted for Vehicle '.((int) $data['vehicle_index'] + 1).'.');
    }

    /**
     * Restore a release marker lost by plan recovery when the audit timeline
     * and vehicle movement independently prove the release already occurred.
     *
     * @param  list<array<string, mixed>>  $vehicles
     * @return list<array<string, mixed>>
     */
    private function restoreConfirmedVehicleReleaseMarkers(DispatchPlan $dispatch, array $vehicles): array
    {
        $releaseAt = collect($dispatch->status_timeline ?? [])
            ->filter(function ($entry): bool {
                if (! is_array($entry) || ($entry['status'] ?? null) !== DispatchPlan::STATUS_RELEASED) {
                    return false;
                }

                return str_contains(strtolower((string) ($entry['note'] ?? '')), 'warehouse release confirmed');
            })
            ->pluck('at')
            ->filter()
            ->map(fn ($at) => Carbon::parse($at))
            ->sortBy(fn (Carbon $at) => $at->getTimestamp())
            ->first();

        if (! $releaseAt) {
            return $vehicles;
        }

        $savedVehicles = $dispatch->resolvedVehicleDetails();
        foreach ($vehicles as $index => &$vehicle) {
            if (filled($vehicle['warehouse_released_at'] ?? null)) {
                continue;
            }

            $savedIndex = (int) ($vehicle['source_vehicle_index'] ?? $index);
            $savedVehicle = $savedVehicles[$savedIndex] ?? [];
            $firstMovement = $dispatch->deliveryUpdates()
                ->where('vehicle_index', $savedIndex)
                ->oldest('occurred_at')
                ->value('occurred_at');
            $departedAt = $vehicle['departed_at'] ?? $savedVehicle['departed_at'] ?? null;
            $movementAt = $firstMovement ?: $departedAt;

            if (! $movementAt || $releaseAt->greaterThan(Carbon::parse($movementAt))) {
                continue;
            }

            $vehicle['warehouse_released_at'] = $releaseAt->format('Y-m-d H:i:s');
        }
        unset($vehicle);

        return $vehicles;
    }

    private function assertDeliveryUpdateSequence(DispatchPlan $dispatch, array $data): void
    {
        $vehicleIndex = (int) $data['vehicle_index'];
        $stage = (string) $data['stage'];
        if (in_array($stage, ['departed', 'arrived', 'unloading_started', 'unloading_completed'], true)) {
            throw_if(
                $dispatch->deliveryUpdates()->where('vehicle_index', $vehicleIndex)->where('stage', $stage)->exists(),
                ValidationException::withMessages(['stage' => Str::headline($stage).' has already been posted for this vehicle. Open the timeline and choose Edit message if only the narrative needs correction.'])
            );
        }
        $requirements = [
            'arrived' => ['departed', 'Record the vehicle departure before reporting its arrival.'],
            'unloading_started' => ['arrived', 'Record arrival at the destination before starting unloading.'],
            'unloading_completed' => ['unloading_started', 'Record the start of unloading before reporting that unloading is complete.'],
        ];
        if (! isset($requirements[$stage])) return;

        [$requiredStage, $message] = $requirements[$stage];
        $previous = $dispatch->deliveryUpdates()->where('vehicle_index', $vehicleIndex)
            ->where('stage', $requiredStage)->latest('occurred_at')->first();
        if ($stage === 'arrived' && ! $previous) {
            $vehicle = $dispatch->resolvedVehicleDetails()[$vehicleIndex] ?? [];
            $departedAt = $vehicle['departed_at'] ?? null;
            if ($departedAt) {
                $previous = new DispatchDeliveryUpdate(['occurred_at' => $departedAt]);
            }
        }
        throw_if(! $previous, ValidationException::withMessages(['stage' => $message]));
        throw_if(
            Carbon::parse($data['occurred_at'])->lessThanOrEqualTo(Carbon::parse($previous->occurred_at)),
            ValidationException::withMessages(['occurred_at' => 'The update time must be after the preceding delivery stage.'])
        );
    }

    public function deliveryUpdatePhoto(Request $request, DispatchPlan $dispatch, DispatchDeliveryUpdate $update, int $photoIndex): HttpResponse
    {
        if (! DispatchMonitoringController::userCanView($request->user())) {
            $this->authorizeDispatchAccess($request, $dispatch);
        }
        abort_unless((int) $update->dispatch_plan_id === (int) $dispatch->id, 404);
        if (! DispatchMonitoringController::userCanView($request->user())) {
            $this->authorizeVehicleAccess($request->user(), $dispatch, (int) $update->vehicle_index);
        }
        $path = ($update->photo_paths ?? [])[$photoIndex] ?? null;
        abort_unless($path && Storage::disk('local')->exists($path), 404);
        return $request->boolean('download')
            ? Storage::disk('local')->download($path, 'delivery-'.$dispatch->id.'-'.$update->stage.'-'.($photoIndex + 1).'.jpg')
            : Storage::disk('local')->response($path);
    }

    private function deliveryUpdateReporterRole(User $user, DispatchPlan $dispatch): string
    {
        if ($this->isAssignedEscort($dispatch, $user)) return 'DSWD Escort';
        if ($this->deliveryUpdateDevelopmentAccess()) return 'Development Workspace Tester';
        if ($this->canManageDispatches($user)) return 'Dispatch Officer';
        return 'Nearby DSWD Storekeeper';
    }

    public function updateDeliveryUpdateMessage(Request $request, DispatchPlan $dispatch, DispatchDeliveryUpdate $update, AuditLogger $audit, RealtimePublisher $realtime): RedirectResponse
    {
        $this->authorizeDispatchAccess($request, $dispatch);
        abort_unless((int) $update->dispatch_plan_id === (int) $dispatch->id, 404);
        abort_unless(
            $request->user() && ($this->deliveryUpdateDevelopmentAccess() || $this->isAssignedEscort($dispatch, $request->user())),
            403,
            'Only the assigned escort may revise a posted field-update message.'
        );
        $this->authorizeVehicleAccess($request->user(), $dispatch, (int) $update->vehicle_index);
        $data = $request->validate(['message' => ['required', 'string', 'min:10', 'max:3000']]);
        $before = $update->only(['message']);
        $update->forceFill(['message' => trim((string) $data['message'])])->save();
        $audit->log('dispatch.delivery_update.message_updated', $update, $before, $update->only(['message']));
        $this->publishDeliveryUpdateRealtime($realtime, $dispatch->fresh());

        return back()->with('success', 'The posted field-update message was revised. Its stage, time, GPS, and evidence remain unchanged.');
    }

    /** Allow complete workflow testing locally without weakening production escort controls. */
    private function deliveryUpdateDevelopmentAccess(): bool
    {
        return app()->environment(['local', 'testing']);
    }
    public function vehicleDr(Request $request, DispatchPlan $dispatch, int $vehicleIndex, RisDrDocumentPdfService $pdf): HttpResponse
    {
        $this->authorizeDispatchAccess($request, $dispatch);
        $this->authorizeVehicleAccess($request->user(), $dispatch, $vehicleIndex);
        $vehicle = array_values($dispatch->resolvedVehicleDetails())[$vehicleIndex] ?? null;
        abort_unless(is_array($vehicle), 404, 'Dispatch vehicle not found.');
        abort_unless(filled($vehicle['warehouse_released_at'] ?? null), 422, 'The Delivery Receipt is assigned only after warehouse release is confirmed.');
        abort_unless($this->vehicleIsAssignedForDr($vehicle), 422, 'Complete and save the vehicle assignment before previewing its Delivery Receipt.');

        return $pdf->streamDispatchVehicleDr($dispatch, $vehicleIndex);
    }

    /** Preview the DR for stock released without transport at the recipient's own warehouse. */
    public function localHandoverDr(Request $request, DispatchPlan $dispatch, RisDrDocumentPdfService $pdf): HttpResponse
    {
        $this->authorizeDispatchAccess($request, $dispatch);
        abort_unless(filled(data_get($dispatch->local_handover_details, 'released_at')), 422, 'The Delivery Receipt is assigned only after local warehouse release is confirmed.');
        abort_unless(filled(data_get($dispatch->local_handover_details, 'dr_number')), 404, 'The local warehouse release does not have an assigned Delivery Receipt.');

        return $pdf->streamDispatchLocalHandoverDr($dispatch);
    }

    public function deliveryEscortWorkspace(Request $request, InventoryBalanceService $inventoryBalanceService): Response
    {
        $request->attributes->set('delivery_escort_workspace', true);

        return $this->index($request, $inventoryBalanceService);
    }

    public function index(Request $request, InventoryBalanceService $inventoryBalanceService): Response
    {
        $escortWorkspace = $request->attributes->getBoolean('delivery_escort_workspace');
        $this->authorizeDispatchAccess($request, escortWorkspace: $escortWorkspace);
        // During the shared monitoring rollout, every authorized workspace
        // user may see active deliveries. Edit authority remains separately
        // restricted to the escort actually assigned to each vehicle.
        $assignedDispatchIds = null;

        // Escort workspace has no Still for Action tab (drafts / ready-to-plan).
        $defaultBucket = $escortWorkspace ? 'in_progress' : 'still_for_action';
        $bucket = (string) $request->query('bucket', $defaultBucket);
        if ($escortWorkspace && $bucket === 'still_for_action') {
            $bucket = 'in_progress';
        }
        $allowedBuckets = $escortWorkspace
            ? ['in_progress', 'completed', 'all']
            : ['still_for_action', 'in_progress', 'completed', 'all'];
        if (! in_array($bucket, $allowedBuckets, true)) {
            $bucket = $defaultBucket;
        }

        $selectedId = $request->integer('dispatch_id') ?: null;
        $sourceRequestId = $request->integer('request_id') ?: null;

        $dispatchesQuery = DispatchPlan::query()
            ->with([
                'request:id,reference_number,status,requesting_agency,lgu,requester,municipality,province,lgu_level,lgu_psgc_code',
                'requisitionIssuanceSlip:id,request_id,ris_number,ris_drn,dr_number,status,reservation_status,ris_date,recipient,delivery_site,receiving_representative,contact_number,tracking_data,updated_at',
                'items',
                'deliveryUpdates.reporter:id,name',
                'creator:id,name',
                'updater:id,name',
            ])
            ->latest();

        // The escort workspace is only for an actual transport assignment.
        // Direct/local handovers (including replacement follow-ups sourced from
        // the recipient LGU warehouse) stay in Dispatch/Delivery and must never
        // acquire a synthetic "Vehicle 1" through legacy model normalization.
        if ($escortWorkspace) {
            $dispatchesQuery->where(function ($query): void {
                $query->where('vehicles_needed', true)
                    ->orWhere('number_of_vehicles', '>', 0);
            });
        }

        if ($assignedDispatchIds !== null) {
            $dispatchesQuery->whereIn('id', $assignedDispatchIds);
        }

        $countQuery = fn () => DispatchPlan::query()
            ->when($assignedDispatchIds !== null, fn ($query) => $query->whereIn('id', $assignedDispatchIds))
            ->when($escortWorkspace, fn ($query) => $query->where(function ($transport): void {
                $transport->where('vehicles_needed', true)
                    ->orWhere('number_of_vehicles', '>', 0);
            }));
        $hasDeferredBalance = fn ($query) => $query
            ->whereIn('variance_disposition', DispatchPlan::REPLACEMENT_REQUIRED_DISPOSITIONS)
            ->whereRaw('COALESCE(allocated_quantity, 0) > COALESCE(received_quantity, 0)');
        $counts = [
            'still_for_action' => $countQuery()->whereIn('status', DispatchPlan::STILL_FOR_ACTION)->count(),
            // The escort workspace tracks completion of the physical delivery.
            // A returned/deferred item still requires dispatch-officer follow-up,
            // but must not leave an acknowledged delivery under Active Deliveries.
            'in_progress' => $escortWorkspace
                ? $countQuery()->whereIn('status', DispatchPlan::IN_PROGRESS)->count()
                : $countQuery()->where(function ($query) use ($hasDeferredBalance): void {
                    $query->whereIn('status', DispatchPlan::IN_PROGRESS)
                        ->orWhere(fn ($received) => $received
                            ->where('status', DispatchPlan::STATUS_RECEIVED)
                            ->whereHas('items', $hasDeferredBalance));
                })->count(),
            'completed' => $escortWorkspace
                ? $countQuery()->whereIn('status', DispatchPlan::COMPLETED)->count()
                : $countQuery()->whereIn('status', DispatchPlan::COMPLETED)
                    ->whereDoesntHave('items', $hasDeferredBalance)->count(),
            'all' => $countQuery()->count(),
        ];

        if ($bucket !== 'all') {
            $statusMap = [
                'still_for_action' => DispatchPlan::STILL_FOR_ACTION,
                'in_progress' => DispatchPlan::IN_PROGRESS,
                'completed' => DispatchPlan::COMPLETED,
            ];
            if ($escortWorkspace && $bucket === 'in_progress') {
                $dispatchesQuery->whereIn('status', DispatchPlan::IN_PROGRESS);
            } elseif ($escortWorkspace && $bucket === 'completed') {
                $dispatchesQuery->whereIn('status', DispatchPlan::COMPLETED);
            } elseif ($bucket === 'in_progress') {
                $dispatchesQuery->where(function ($query) use ($hasDeferredBalance): void {
                    $query->whereIn('status', DispatchPlan::IN_PROGRESS)
                        ->orWhere(fn ($received) => $received
                            ->where('status', DispatchPlan::STATUS_RECEIVED)
                            ->whereHas('items', $hasDeferredBalance));
                });
            } elseif ($bucket === 'completed') {
                $dispatchesQuery->whereIn('status', DispatchPlan::COMPLETED)
                    ->whereDoesntHave('items', $hasDeferredBalance);
            } else {
                $dispatchesQuery->whereIn('status', $statusMap[$bucket]);
            }
        }

        $dispatches = $dispatchesQuery->paginate(15)->withQueryString()
            ->through(fn (DispatchPlan $dispatch) => $this->serializeDispatch($dispatch, $request->user(), $escortWorkspace));

        $eligibleRelations = [
            'requisitionIssuanceSlip.allocationItems',
            'sourceLguDromicReport:id,reference_number,lgu_relief_request_reference,lgu_signed_request_path,lgu_signed_report_path',
            'epirmaSignedDocuments' => fn ($query) => $query
                ->where('routing_status', 'signed')
                ->orderByDesc('id'),
        ];

        $eligibleRequests = AssistanceRequest::query()
            ->with($eligibleRelations)
            ->whereHas('requisitionIssuanceSlip', fn ($slip) => $slip->whereIn('status', RequisitionIssuanceSlip::DISPATCH_READY_STATUSES))
            ->whereDoesntHave('dispatchPlans')
            ->latest()
            ->get([
                'id',
                'reference_number',
                'status',
                'requesting_agency',
                'lgu',
                'municipality',
                'province',
                'lgu_psgc_code',
                'requester',
                'purpose',
                'source_lgu_dromic_request_id',
                'source_document_url',
            ]);

        if (! $escortWorkspace) {
            $counts['still_for_action'] += $eligibleRequests->count();
        }

        $selectedDispatch = null;
        if ($selectedId) {
            $selectedDispatch = DispatchPlan::query()
                ->with([
                    'request:id,reference_number,status,requesting_agency,lgu,municipality,province,lgu_level,lgu_psgc_code,requester,purpose',
                    'requisitionIssuanceSlip.allocationItems',
                    'items',
                    'deliveryUpdates.reporter:id,name',
                    'creator:id,name',
                    'updater:id,name',
                ])
                ->when($assignedDispatchIds !== null, fn ($query) => $query->whereIn('id', $assignedDispatchIds))
                ->when($escortWorkspace, fn ($query) => $query->where(function ($transport): void {
                    $transport->where('vehicles_needed', true)
                        ->orWhere('number_of_vehicles', '>', 0);
                }))
                ->find($selectedId);
        }

        $sourceRequest = null;
        if (! $selectedDispatch && $sourceRequestId) {
            $sourceRequest = AssistanceRequest::query()
                ->with($eligibleRelations)
                ->whereKey($sourceRequestId)
                ->whereHas('requisitionIssuanceSlip', fn ($slip) => $slip->whereIn('status', RequisitionIssuanceSlip::DISPATCH_READY_STATUSES))
                ->first();
        }

        $warehouseStock = $inventoryBalanceService->balanceRows()
            ->map(fn (array $row): array => [
                'warehouse_id' => $row['warehouse_id'] ?? null,
                'item' => $row['item'] ?? '',
                'available' => max(0, (float) ($row['available_balance'] ?? 0)),
                'expiry' => $row['expiry'] ?? null,
            ])
            ->filter(fn (array $row): bool => filled($row['warehouse_id']) && filled($row['item']))
            ->values();

        $warehouseCatalog = Warehouse::query()
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'warehouse_type',
                'ownership',
                'municipality',
                'province',
                'category',
                'partnership',
                'office',
                'contact_person',
                'contact_number',
                'designated_storekeepers',
                'storekeeper_contact_number',
            ])
            ->mapWithKeys(fn (Warehouse $warehouse) => [
                (string) $warehouse->id => [
                    'id' => $warehouse->id,
                    'name' => $warehouse->name,
                    'display_name' => $warehouse->display_name,
                    'warehouse_type' => $warehouse->warehouse_type,
                    'ownership' => $warehouse->ownership,
                    'municipality' => $warehouse->municipality,
                    'province' => $warehouse->province,
                    'category' => $warehouse->category,
                    'partnership' => $warehouse->partnership,
                    'office' => $warehouse->office,
                    'contact_person' => $warehouse->contact_person,
                    'contact_number' => $warehouse->contact_number,
                    'designated_storekeepers' => $warehouse->designated_storekeepers,
                    'storekeeper_contact_number' => $warehouse->storekeeper_contact_number,
                    'storekeeper_id_number' => null,
                    'storekeeper_position' => filled($warehouse->designated_storekeepers)
                        ? 'Warehouse Storekeeper'
                        : null,
                ],
            ]);

        return Inertia::render('Requests/Dispatches', [
            'bucket' => $bucket,
            'counts' => $counts,
            'dispatches' => $dispatches,
            'eligibleRequests' => $escortWorkspace
                ? []
                : $eligibleRequests->map(fn (AssistanceRequest $row) => $this->serializeEligibleRequest($row))->values(),
            'selectedDispatch' => $selectedDispatch ? $this->serializeDispatch($selectedDispatch, $request->user(), $escortWorkspace) : null,
            'sourceRequest' => ! $escortWorkspace && $sourceRequest
                ? $this->serializeEligibleRequest($sourceRequest)
                : null,
            'libraryOptions' => OperationalLibraryValue::groupedOptions(),
            'landTransportationSources' => OperationalLibraryValue::query()
                ->where('library_type', 'transportation_source')->where('context', 'land')->where('is_active', true)
                ->orderBy('value')->pluck('value')->unique()->values(),
            'dispatchContactLibraries' => [
                'dispatch_driver' => $this->driverCatalogWithMetadata(),
                'dispatch_received_by' => $this->receivedByCatalogWithLguMetadata(),
            ],
            'rrosSignatories' => OperationalLibraryValue::query()
                ->whereIn('library_type', ['rros_ris_signatory', 'rros_dr_signatory'])
                ->where('is_active', true)
                ->orderBy('library_type')
                ->orderBy('context')
                ->get(['id', 'library_type', 'value', 'context', 'metadata']),
            'statuses' => DispatchPlan::STATUSES,
            'warehouseStock' => $warehouseStock,
            'warehouseReservations' => app(RisReservationService::class)->payload(),
            'warehouseCatalog' => $warehouseCatalog,
            'escortWorkspace' => $escortWorkspace,
            'focusVehicleIndex' => $escortWorkspace && $request->query->has('focus_vehicle')
                ? max(0, $request->integer('focus_vehicle'))
                : null,
            'canManageDispatches' => $this->canManageDispatches($request->user()),
        ]);
    }

    public function store(Request $request, AuditLogger $audit, WorkflowNotificationService $workflowNotifications, RealtimePublisher $realtime): RedirectResponse
    {
        $this->authorizeDispatchAccess($request);

        $data = $this->validatedPayload($request, creating: true, existing: null);
        $data['dispatcher'] = $this->dispatchOfficerName($request);
        $assistanceRequest = AssistanceRequest::query()
            ->with(['requisitionIssuanceSlip.allocationItems', 'encoder', 'items', 'assessmentType'])
            ->findOrFail($data['request_id']);

        abort_unless(
            $assistanceRequest->requisitionIssuanceSlip
            && in_array($assistanceRequest->requisitionIssuanceSlip->status, RequisitionIssuanceSlip::DISPATCH_READY_STATUSES, true),
            422,
            'Dispatch Plan requires an approved RIS / DR.',
        );

        abort_if(
            DispatchPlan::query()->where('request_id', $assistanceRequest->id)->exists(),
            422,
            'A Dispatch Plan already exists for this request.',
        );

        $status = $data['status'] ?? DispatchPlan::STATUS_DRAFT;
        if (empty($data['items']) && $assistanceRequest->requisitionIssuanceSlip) {
            $data['items'] = $this->itemsFromSlip($assistanceRequest->requisitionIssuanceSlip)->all();
            $data = $this->applySourceWarehouseDefaults($data);
        }
        $data['request_id'] = $assistanceRequest->id;
        if (blank($data['receiving_agency_lgu'] ?? null)) {
            $data['receiving_agency_lgu'] = $assistanceRequest->requisitionIssuanceSlip?->recipient
                ?? $assistanceRequest->requesting_agency;
        }
        $data = $this->pruneLocalOnlyTransportVehicles($data);
        $this->assertNoReceivingLguVehicles($data);
        $this->assertStatusRequirements($data, $status, fromStatus: null);

        $dispatch = DB::transaction(function () use ($request, $data, $assistanceRequest, $status): DispatchPlan {
            $slip = $assistanceRequest->requisitionIssuanceSlip;
            $seeded = $this->seedFromRis($slip, $data);
            $seeded['vehicle_details'] = $this->assignVehicleDrNumbers(
                $seeded['vehicle_details'] ?? [],
                [],
                $slip,
                offset: 0,
                forceSuffix: $this->hasLocalSourceAllocation([...$seeded, 'items' => $data['items'] ?? []]),
            );
            $seeded = Arr::except($this->assignLocalHandoverDrNumber(
                [...$seeded, 'items' => $data['items'] ?? []],
                $slip,
            ), ['items']);

            $dispatch = DispatchPlan::create([
                ...$seeded,
                'dispatcher' => $data['dispatcher'],
                'dispatch_number' => 'DSP-'.now()->format('Ymd').'-'.Str::upper(Str::random(5)),
                'request_id' => $assistanceRequest->id,
                'requisition_issuance_slip_id' => $slip->id,
                'status' => $status,
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
            ]);
            $dispatch->appendTimeline(
                $status,
                $request->user()?->id,
                'Dispatch plan created',
                fromStatus: null,
                type: 'status_change',
                byName: $request->user()?->name,
            );
            $dispatch->save();

            $this->syncItems($dispatch, $data['items'] ?? null, $slip);

            return $dispatch->fresh(['items', 'request', 'requisitionIssuanceSlip']);
        });

        $this->syncRequestWorkflowStatus($dispatch);
        $audit->log('dispatch.created', $dispatch, [], $dispatch->toArray());
        $workflowNotifications->notifyDispatchStatusChanged($dispatch->request->fresh(), $dispatch, previousStatus: null);
        $this->publishRealtime($realtime, $dispatch, 'dispatch.created');

        return redirect()
            ->route('dispatches.index', [
                'bucket' => $dispatch->bucket(),
                'dispatch_id' => $dispatch->id,
            ])
            ->with('success', count($dispatch->resolvedVehicleDetails()) + (filled(data_get($dispatch->local_handover_details, 'dr_number')) ? 1 : 0).' Delivery Receipt(s) created. The dispatch plan was saved.');
    }

    public function update(
        Request $request,
        DispatchPlan $dispatch,
        AuditLogger $audit,
        WorkflowNotificationService $workflowNotifications,
        RealtimePublisher $realtime,
        DispatchInventoryIssuanceService $inventoryIssuance,
        DispatchInventoryReturnService $inventoryReturns,
    ): RedirectResponse {
        $this->authorizeDispatchAccess($request, $dispatch);

        $before = $dispatch->toArray();
        $previousStatus = (string) $dispatch->status;
        $data = $this->validatedPayload($request, creating: false, existing: $dispatch);
        // A direct/local release is submitted from a workspace that may carry
        // an empty transport form. Never let that stale form silently erase
        // vehicle transactions that were already confirmed during Planning.
        $protectVehiclesFromLocalTransaction = $request->boolean('release_local_handover')
            || $request->boolean('update_local_receipt');
        if ($this->canManageDispatches($request->user()) && $protectVehiclesFromLocalTransaction && $previousStatus !== DispatchPlan::STATUS_DRAFT) {
            $savedVehicles = collect($dispatch->resolvedVehicleDetails());
            $submittedVehicles = array_key_exists('vehicle_details', $data) && is_array($data['vehicle_details'])
                ? array_values($data['vehicle_details'])
                : null;
            if ($submittedVehicles !== null) {
                foreach ($savedVehicles as $index => $savedVehicle) {
                    $confirmed = filled($savedVehicle['plan_confirmed_at'] ?? null)
                        || filled($savedVehicle['warehouse_released_at'] ?? null)
                        || filled($savedVehicle['departed_at'] ?? null)
                        || filled($savedVehicle['received_at'] ?? null);
                    if ($confirmed && ! array_key_exists($index, $submittedVehicles)) {
                        $submittedVehicles[$index] = $savedVehicle;
                    }
                }
                ksort($submittedVehicles);
                $data['vehicle_details'] = array_values($submittedVehicles);
            }
            if ($savedVehicles->contains(fn (array $vehicle): bool => filled($vehicle['plan_confirmed_at'] ?? null))) {
                $data['vehicles_needed'] = true;
                $data['number_of_vehicles'] = max((int) ($data['number_of_vehicles'] ?? 0), $savedVehicles->count());
            }
        }
        // Never let a stale browser form erase already-confirmed workflow
        // milestones. This is especially important for the independent
        // no-transport plan, whose confirmation is nested in the local
        // handover JSON and may not be present in a vehicle-only submission.
        if (array_key_exists('local_handover_details', $data)) {
            $submittedLocal = is_array($data['local_handover_details'] ?? null)
                ? $data['local_handover_details']
                : [];
            $savedLocal = is_array($dispatch->local_handover_details)
                ? $dispatch->local_handover_details
                : [];
            foreach (['plan_confirmed_at', 'released_at', 'received_at', 'dr_number'] as $milestone) {
                if (filled($savedLocal[$milestone] ?? null) && blank($submittedLocal[$milestone] ?? null)) {
                    $submittedLocal[$milestone] = $savedLocal[$milestone];
                }
            }
            if (($savedLocal['receipt_acknowledged'] ?? false) && ! ($submittedLocal['receipt_acknowledged'] ?? false)) {
                $submittedLocal['receipt_acknowledged'] = true;
            }
            $data['local_handover_details'] = $submittedLocal;
        }
        if (array_key_exists('vehicle_details', $data)) {
            $savedVehicles = $dispatch->resolvedVehicleDetails();
            $data['vehicle_details'] = collect($data['vehicle_details'] ?? [])
                ->values()
                ->map(function (array $row, int $index) use ($savedVehicles): array {
                    // Escort workspaces submit a compact list containing only
                    // their assigned vehicles. Keep milestones aligned with
                    // the persisted fleet row rather than the compact index.
                    $savedIndex = (int) ($row['source_vehicle_index'] ?? $index);
                    $saved = $savedVehicles[$savedIndex] ?? [];
                    foreach (['plan_confirmed_at', 'warehouse_released_at', 'departed_at', 'received_at', 'dr_number'] as $milestone) {
                        if (filled($saved[$milestone] ?? null) && blank($row[$milestone] ?? null)) {
                            $row[$milestone] = $saved[$milestone];
                        }
                    }
                    if (($saved['receipt_acknowledged'] ?? false) && ! ($row['receipt_acknowledged'] ?? false)) {
                        $row['receipt_acknowledged'] = true;
                    }
                    return $row;
                })->all();

            // A recovered vehicle plan can retain its delivery updates while
            // losing the nested release timestamp. Restore that workflow
            // marker from the confirmed-release timeline only when the same
            // vehicle has recorded movement. Inventory is not posted here.
            $data['vehicle_details'] = $this->restoreConfirmedVehicleReleaseMarkers(
                $dispatch,
                $data['vehicle_details'],
            );
        }
        // Workspace context and authorization are separate concerns. An RROS
        // administrator may test or monitor the escort workspace without being
        // treated as an escort for write constraints, but must still return to
        // the page where the transaction was submitted.
        $returnToEscortWorkspace = $request->boolean('return_to_escort_workspace');
        $escortUpdate = ! $this->canManageDispatches($request->user());
        if ($escortUpdate) {
            $data = $this->constrainEscortUpdate($dispatch, $data, $request->user());
        }
        $data['dispatcher'] = $escortUpdate
            ? $dispatch->dispatcher
            : $this->dispatchOfficerName($request);
        $status = $data['status'] ?? $previousStatus;
        if (
            $previousStatus !== DispatchPlan::STATUS_DRAFT
            && array_key_exists('vehicle_details', $data)
            && count($data['vehicle_details'] ?? []) > count($dispatch->resolvedVehicleDetails())
        ) {
            throw ValidationException::withMessages([
                'vehicle_details' => 'Vehicles can only be added during Planning, before the dispatch is marked Planned.',
            ]);
        }
        $this->assertReleaseFollowsSavedPlan($dispatch, $data, $status, $previousStatus);
        $assertPayload = array_merge($dispatch->toArray(), $data);
        if (empty($assertPayload['items'])) {
            $dispatch->loadMissing('items');
            $assertPayload['items'] = $dispatch->items->map(fn ($item) => [
                'requisition_issuance_item_id' => $item->requisition_issuance_item_id,
                'request_item_id' => $item->request_item_id,
                'warehouse_id' => $item->warehouse_id,
                'item_name' => $item->item_name,
                'unit' => $item->unit,
                'warehouse_name' => $item->warehouse_name,
                'allocated_quantity' => $item->allocated_quantity,
                'loaded_quantity' => $item->loaded_quantity,
                'received_quantity' => $item->received_quantity,
                'variance_disposition' => $item->variance_disposition,
                'variance_resolution' => $item->variance_resolution,
                'return_condition' => $item->return_condition,
                'return_stock_disposition' => $item->return_stock_disposition,
                'return_received_at' => optional($item->return_received_at)?->format('Y-m-d\TH:i'),
                'return_inspected_by' => $item->return_inspected_by,
                'return_reason' => $item->return_reason,
                'remarks' => $item->remarks,
            ])->all();
        }
        $assertPayload = $this->applySourceWarehouseDefaults($assertPayload);
        $assertPayload['request_id'] = $assertPayload['request_id'] ?? $dispatch->request_id;
        $assertPayload = $this->pruneLocalOnlyTransportVehicles($assertPayload);
        $this->assertNoReceivingLguVehicles($assertPayload);
        // Persist auto-filled / pruned vehicles onto the save payload when present.
        if (array_key_exists('vehicle_details', $assertPayload)) {
            $assertPayload['vehicle_details'] = $this->assignVehicleDrNumbers(
                $assertPayload['vehicle_details'],
                $dispatch->resolvedVehicleDetails(),
                $dispatch->requisitionIssuanceSlip,
                (int) $dispatch->dr_series_offset,
                (int) $dispatch->delivery_sequence > 1 || $this->hasLocalSourceAllocation($assertPayload),
            );
            $data['vehicle_details'] = $assertPayload['vehicle_details'];
            if (array_key_exists('number_of_vehicles', $assertPayload)) {
                $data['number_of_vehicles'] = $assertPayload['number_of_vehicles'];
            }
        }
        $assertPayload = $this->assignLocalHandoverDrNumber($assertPayload, $dispatch->requisitionIssuanceSlip);
        $data['local_handover_details'] = $assertPayload['local_handover_details'] ?? ($data['local_handover_details'] ?? []);

        $planVehicleIndexes = collect($data['plan_vehicle_indexes'] ?? [])
            ->map(fn ($index) => (int) $index)->unique()->values()->all();
        $planLocalHandover = (bool) ($data['plan_local_handover'] ?? false);
        $releaseVehicleIndexes = collect($data['release_vehicle_indexes'] ?? [])
            ->map(fn ($index) => (int) $index)
            ->unique()
            ->values()
            ->all();
        $transitVehicleIndexes = collect($data['transit_vehicle_indexes'] ?? [])
            ->map(fn ($index) => (int) $index)->unique()->values()->all();
        $receiptVehicleIndexes = collect($data['receipt_vehicle_indexes'] ?? [])
            ->map(fn ($index) => (int) $index)->unique()->values()->all();
        $scopedVehiclePlan = $planVehicleIndexes !== [];
        $scopedLocalPlan = $planLocalHandover;
        $scopedVehicleTransit = $transitVehicleIndexes !== [];
        $scopedWarehouseRelease = $releaseVehicleIndexes !== [];
        $scopedVehicleReceipt = $receiptVehicleIndexes !== [];
        // Read the transaction command from both the validated payload and the
        // original request. This prevents a direct/no-transport release from
        // falling through to the legacy dispatch-wide status validator.
        $scopedLocalRelease = (bool) ($data['release_local_handover'] ?? false)
            || $request->boolean('release_local_handover');
        $scopedLocalReceiptUpdate = (bool) ($data['update_local_receipt'] ?? false)
            || $request->boolean('update_local_receipt');
        if ($scopedLocalRelease) {
            $data['release_local_handover'] = true;
            $assertPayload['release_local_handover'] = true;
        }

        if ($scopedVehiclePlan) {
            $this->assertVehicleTransactionsPlannable($assertPayload, $planVehicleIndexes);
            foreach ($planVehicleIndexes as $index) {
                $assertPayload['vehicle_details'][$index]['plan_confirmed_at'] ??= now()->format('Y-m-d H:i:s');
            }
            $data['vehicle_details'] = $assertPayload['vehicle_details'];
        }
        if ($scopedLocalPlan) {
            $local = $assertPayload['local_handover_details'] ?? [];
            $localErrors = [];
            if (blank($local['expected_release_at'] ?? null)) {
                $localErrors['local_handover_details.expected_release_at'] = 'Expected release date and time is required before confirming this direct-release plan.';
            }
            if ($localErrors !== []) throw ValidationException::withMessages($localErrors);
            $assertPayload['local_handover_details']['plan_confirmed_at'] ??= now()->format('Y-m-d H:i:s');
            $data['local_handover_details'] = $assertPayload['local_handover_details'];
        }
        if ($scopedVehiclePlan || $scopedLocalPlan) {
            $assertPayload = $this->assignDrNumbersByPlanSequence(
                $assertPayload,
                $dispatch->resolvedVehicleDetails(),
                $dispatch->local_handover_details ?? [],
                $dispatch->requisitionIssuanceSlip,
                $planVehicleIndexes,
                $scopedLocalPlan,
            );
            $data['vehicle_details'] = $assertPayload['vehicle_details'] ?? [];
            $data['local_handover_details'] = $assertPayload['local_handover_details'] ?? [];
        }
        if ($scopedWarehouseRelease) {
            $this->assertVehicleTransactionsReleasable($assertPayload, $releaseVehicleIndexes);
        }

        if ($scopedLocalRelease || $scopedLocalReceiptUpdate) {
            $local = $assertPayload['local_handover_details'] ?? [];
            $legacyAlreadyPlanned = in_array($data['status'] ?? null, [
                DispatchPlan::STATUS_PLANNED,
                DispatchPlan::STATUS_RELEASED,
                DispatchPlan::STATUS_IN_TRANSIT,
                DispatchPlan::STATUS_RECEIVED,
            ], true);
            if (blank($local['plan_confirmed_at'] ?? null) && ! $legacyAlreadyPlanned) {
                throw ValidationException::withMessages(['local_handover_details.plan_confirmed_at' => 'Confirm this direct-release plan before release and receipt.']);
            }
            $requiredLocal = [
                'released_at' => 'Actual release date and time',
                'release_witness_affiliation' => 'Released/witnessed by organization',
                'released_by' => 'Released/witnessed by',
                'releaser_contact' => 'Witness contact number',
                'releaser_position' => 'Witness position',
                'releaser_office' => 'Witness office',
                'received_by' => 'Actual receiving representative',
                'receiver_contact' => 'Recipient contact number',
                'receiver_position' => 'Recipient position',
                'receiver_office' => 'Recipient office',
                'received_at' => 'Receipt date and time',
            ];
            $localErrors = [];
            foreach ($requiredLocal as $field => $label) {
                if (blank($local[$field] ?? null)) $localErrors["local_handover_details.$field"] = "$label is required for the local warehouse release and receipt.";
            }
            if (! ($local['receipt_acknowledged'] ?? false)) {
                $localErrors['local_handover_details.receipt_acknowledged'] = 'Confirm that the recipient received the goods from this warehouse.';
            }
            if ($localErrors !== []) throw ValidationException::withMessages($localErrors);
        }
        if ($scopedVehicleTransit) {
            $transitErrors = [];
            foreach ($transitVehicleIndexes as $index) {
                $row = $assertPayload['vehicle_details'][$index] ?? [];
                if (blank($row['warehouse_released_at'] ?? null)) $transitErrors["vehicle_details.$index.warehouse_released_at"] = 'Confirm this vehicle release before marking it In Transit.';
                if (blank($row['departed_at'] ?? null)) $transitErrors["vehicle_details.$index.departed_at"] = 'Actual departure date and time is required for this vehicle.';
            }
            if ($transitErrors !== []) throw ValidationException::withMessages($transitErrors);
        }
        if ($scopedVehicleReceipt) {
            $receiptErrors = [];
            foreach ($receiptVehicleIndexes as $index) {
                // The receipt UI defaults this value to the current local time.
                // Keep the command reliable for stale/mobile clients as well:
                // default only a blank value and never replace a user-selected
                // earlier receipt time.
                if (blank($assertPayload['vehicle_details'][$index]['received_at'] ?? null)) {
                    $assertPayload['vehicle_details'][$index]['received_at'] = now()->format('Y-m-d H:i:s');
                    $data['vehicle_details'][$index]['received_at'] = $assertPayload['vehicle_details'][$index]['received_at'];
                }
                $row = $assertPayload['vehicle_details'][$index] ?? [];
                $savedRow = $dispatch->resolvedVehicleDetails()[$index] ?? [];
                $isPickup = ($row['fulfillment_type'] ?? $assertPayload['fulfillment_type'] ?? DispatchPlan::FULFILLMENT_FIELD_DELIVERY)
                    === DispatchPlan::FULFILLMENT_WAREHOUSE_PICKUP;
                if (blank($row['warehouse_released_at'] ?? null)) $receiptErrors["vehicle_details.$index.warehouse_released_at"] = 'Confirm this vehicle release before recipient receipt.';
                foreach (['received_by', 'received_by_position', 'received_by_office', 'receiver_contact'] as $field) {
                    if (blank($row[$field] ?? null)) $receiptErrors["vehicle_details.$index.$field"] = 'This recipient receipt field is required.';
                }
                if (blank($row['received_at'] ?? null)) $receiptErrors["vehicle_details.$index.received_at"] = 'Receipt date and time is required.';
                if (! $isPickup && blank($row['departed_at'] ?? null)) $receiptErrors["vehicle_details.$index.departed_at"] = 'Mark this vehicle In Transit before recipient receipt.';
                $unloadingCompletedUpdate = $isPickup ? null : $dispatch->deliveryUpdates()
                    ->where('vehicle_index', $index)
                    ->where('stage', 'unloading_completed')
                    ->latest('occurred_at')
                    ->first();
                if (! $isPickup
                    && ! (filled($savedRow['received_at'] ?? null) && (bool) ($savedRow['receipt_acknowledged'] ?? false))
                    && ! $unloadingCompletedUpdate) {
                    $receiptErrors["vehicle_details.$index.transit_completed"] = 'The assigned escort must post Unloading Completed before Recipient Receipt can be confirmed.';
                }
                if (filled($row['received_at'] ?? null)) {
                    $receiptAt = Carbon::parse($row['received_at']);
                    if ($receiptAt->isFuture()) {
                        $receiptErrors["vehicle_details.$index.received_at"] = 'Receipt date and time cannot be later than the current date and time.';
                    } elseif ($unloadingCompletedUpdate && $receiptAt->lessThan($unloadingCompletedUpdate->occurred_at)) {
                        $receiptErrors["vehicle_details.$index.received_at"] = 'Receipt date and time cannot be earlier than the Unloading Completed update.';
                    }
                }
                if (! ($row['receipt_acknowledged'] ?? false)) $receiptErrors["vehicle_details.$index.receipt_acknowledged"] = 'Confirm that the recipient received the goods.';

                // Arrival and delivery completion are workflow facts, not
                // duplicate receipt inputs. Derive arrival from the escort's
                // field timeline and completion from the recorded quantities.
                if (! $isPickup) {
                    $arrivalUpdate = $dispatch->deliveryUpdates()
                        ->where('vehicle_index', $index)
                        ->where('stage', 'arrived')
                        ->latest('occurred_at')
                        ->first();
                    $arrivalUpdate ??= $dispatch->deliveryUpdates()
                        ->where('vehicle_index', $index)
                        ->whereIn('stage', ['unloading_started', 'unloading_completed'])
                        ->oldest('occurred_at')
                        ->first();
                    $row['actual_arrival'] = $arrivalUpdate?->occurred_at?->format('Y-m-d\TH:i')
                        ?? ($row['received_at'] ?? null);
                    $row['delivered_at'] = filled($row['actual_arrival'] ?? null)
                        ? substr((string) $row['actual_arrival'], 0, 10)
                        : null;
                }
                $hasUndelivered = collect($assertPayload['items'] ?? [])->contains(function ($item): bool {
                    $expected = (int) ($item['allocated_quantity'] ?? 0);
                    $accepted = (int) ($item['received_quantity'] ?? $expected);
                    return $accepted < $expected;
                });
                $row['fully_delivered'] = $hasUndelivered ? 'No' : 'Yes';
                $assertPayload['vehicle_details'][$index] = $row;
                $data['vehicle_details'][$index] = $row;
            }
            if ($receiptErrors !== []) throw ValidationException::withMessages($receiptErrors);
        }

        if ($scopedLocalRelease) {
            $assertPayload = $this->assignDrNumbersByPlanSequence(
                $assertPayload,
                $dispatch->resolvedVehicleDetails(),
                $dispatch->local_handover_details ?? [],
                $dispatch->requisitionIssuanceSlip,
                [],
                releaseLocal: true,
            );
            $data['local_handover_details'] = $assertPayload['local_handover_details'] ?? [];
        } elseif ($scopedWarehouseRelease) {
            $assertPayload = $this->assignDrNumbersByPlanSequence(
                $assertPayload,
                $dispatch->resolvedVehicleDetails(),
                $dispatch->local_handover_details ?? [],
                $dispatch->requisitionIssuanceSlip,
                $releaseVehicleIndexes,
                releaseLocal: false,
            );
            $data['vehicle_details'] = $assertPayload['vehicle_details'];
        } elseif (
            in_array($status, [DispatchPlan::STATUS_RELEASED, DispatchPlan::STATUS_IN_TRANSIT, DispatchPlan::STATUS_RECEIVED], true)
            && in_array($previousStatus, [DispatchPlan::STATUS_DRAFT, DispatchPlan::STATUS_PLANNED], true)
        ) {
            $assertPayload = $this->assignDrNumbersByPlanSequence(
                $assertPayload,
                $dispatch->resolvedVehicleDetails(),
                $dispatch->local_handover_details ?? [],
                $dispatch->requisitionIssuanceSlip,
                array_keys($assertPayload['vehicle_details'] ?? []),
                releaseLocal: filled(data_get($assertPayload, 'local_handover_details.released_at')),
            );
            $data['vehicle_details'] = $assertPayload['vehicle_details'] ?? [];
            $data['local_handover_details'] = $assertPayload['local_handover_details'] ?? [];
        }

        $transactionCommand = $scopedVehiclePlan || $scopedLocalPlan || $scopedWarehouseRelease
            || $scopedLocalRelease || $scopedLocalReceiptUpdate || $scopedVehicleTransit || $scopedVehicleReceipt;
        if ($transactionCommand) {
            $status = $this->deriveDispatchStatusFromTransactions($assertPayload);
            $data['status'] = $status;
            // Every scoped command is validated against its own transaction
            // above. Do not run the legacy dispatch-wide status gate here: a
            // direct/no-transport receipt must not require vehicle/loading data,
            // and one vehicle receipt must not require unfinished sibling
            // vehicles. The roll-up status remains derived from all persisted
            // transaction milestones.
        } else {
            // Status is always a roll-up of persisted transaction milestones;
            // never accept a stale client-side downgrade to Draft.
            $status = $this->deriveDispatchStatusFromTransactions($assertPayload);
            $data['status'] = $status;
            $this->assertStatusRequirements(
                $assertPayload,
                $status,
                fromStatus: $previousStatus,
            );
        }

        $recordsFullRelease = ! $transactionCommand && in_array($status, [
            DispatchPlan::STATUS_RELEASED,
            DispatchPlan::STATUS_IN_TRANSIT,
            DispatchPlan::STATUS_RECEIVED,
        ], true) && in_array($previousStatus, [DispatchPlan::STATUS_DRAFT, DispatchPlan::STATUS_PLANNED], true);
        $recordsPartialRelease = $scopedWarehouseRelease || $scopedLocalRelease;
        $recordsRelease = $recordsFullRelease || $recordsPartialRelease;
        $issuanceVehicleIndexes = $scopedWarehouseRelease ? $releaseVehicleIndexes : null;
        $allReleaseTransactionsComplete = $this->allTransportVehiclesReleased($assertPayload)
            && (! $this->hasLocalSourceAllocation($assertPayload)
                || filled(data_get($assertPayload, 'local_handover_details.released_at')));

        DB::transaction(function () use (
            $request,
            $dispatch,
            $data,
            $status,
            $previousStatus,
            $inventoryIssuance,
            $inventoryReturns,
            $recordsRelease,
            $recordsFullRelease,
            $recordsPartialRelease,
            $issuanceVehicleIndexes,
            $scopedLocalRelease,
            $scopedLocalReceiptUpdate,
            $scopedVehicleReceipt,
            $allReleaseTransactionsComplete,
        ): void {
            $payload = Arr::except($data, ['items', 'request_id', 'status', 'dispatch_officer', 'plan_vehicle_indexes', 'plan_local_handover', 'release_vehicle_indexes', 'release_local_handover', 'update_local_receipt', 'receipt_vehicle_indexes', 'transit_vehicle_indexes', 'return_to_escort_workspace']);
            $dispatch->fill([
                ...$payload,
                'dispatcher' => $data['dispatcher'],
                'status' => $status,
                'updated_by' => $request->user()?->id,
            ]);

            $issuanceIds = [];
            if ($recordsRelease) {
                if (array_key_exists('items', $data)) {
                    $this->syncItems($dispatch, $data['items'], $dispatch->requisitionIssuanceSlip);
                    $dispatch->unsetRelation('items');
                }
                // Persist vehicle release stamps before issuing so DR matching uses saved rows.
                $dispatch->save();
                $issuanceIds = $inventoryIssuance->record(
                    $dispatch->fresh(['items', 'requisitionIssuanceSlip.allocationItems', 'request.incident']),
                    $request->user(),
                    $issuanceVehicleIndexes,
                    $scopedLocalRelease,
                );
            }

            if ($status !== $previousStatus) {
                $statusNote = $recordsRelease
                    ? (
                        $issuanceIds === []
                            ? 'Warehouse release confirmed (inventory already posted for these Delivery Receipts).'
                            : 'Warehouse release confirmed; system inventory issuance transaction(s) '.implode(', ', $issuanceIds).' recorded pending WIT reconciliation'
                    )
                    : 'Status updated';
                $dispatch->appendTimeline(
                    $status,
                    $request->user()?->id,
                    $statusNote,
                    fromStatus: $previousStatus,
                    type: 'status_change',
                    byName: $request->user()?->name,
                );
            } else {
                $dispatch->appendTimeline(
                    $status,
                    $request->user()?->id,
                    $recordsPartialRelease
                        ? (
                            $issuanceIds === []
                                ? 'Partial warehouse release confirmed (inventory already posted for these Delivery Receipts).'
                                : 'Partial warehouse release confirmed; system inventory issuance transaction(s) '.implode(', ', $issuanceIds).' recorded pending WIT reconciliation'
                        )
                        : ($scopedLocalReceiptUpdate
                            ? 'Direct release and recipient receipt details updated'
                            : ($scopedVehicleReceipt
                                ? 'Recipient receipt confirmed by the assigned delivery escort'
                                : 'Plan details updated')),
                    fromStatus: $previousStatus,
                    type: $recordsPartialRelease ? 'status_change' : 'plan_update',
                    byName: $request->user()?->name,
                );
            }

            if (! $recordsRelease) {
                $dispatch->save();
            } else {
                // Re-save in case timeline mutated attributes after the pre-issuance save.
                $dispatch->save();
            }

            // WIT issuance belongs to the actual Dispatch release event. Releasing the
            // reservation here makes the quantities available without prematurely
            // marking an approved RIS Completed when its Post-RIS uploads are incomplete.
            if ($recordsFullRelease || $allReleaseTransactionsComplete) {
                $slip = $dispatch->requisitionIssuanceSlip()->lockForUpdate()->first();
                if ($slip && $slip->reservation_status === 'active') {
                    $slipUpdates = ['reservation_status' => 'released'];
                    $slip->update($slipUpdates);
                }
            }
            // A direct/no-transport confirmation can release and receive in one
            // command. Persist its item outcomes after posting the release so
            // return/cancellation quantities are not lost before return handling.
            if (array_key_exists('items', $data) && (! $recordsRelease || $status === DispatchPlan::STATUS_RECEIVED)) {
                $this->syncItems($dispatch, $data['items'], $dispatch->requisitionIssuanceSlip);
            }
            if ($status === DispatchPlan::STATUS_RECEIVED) {
                $dispatch->save();
                $dispatch->unsetRelation('items');
                $inventoryReturns->record($dispatch, $request->user());
            }
        });

        $dispatch = $dispatch->fresh(['items', 'request', 'requisitionIssuanceSlip']);
        $this->syncRisDeliveryCompletion($dispatch);
        $this->syncRequestWorkflowStatus($dispatch);
        $audit->log('dispatch.updated', $dispatch, $before, $dispatch->toArray());
        $workflowNotifications->notifyDispatchStatusChanged($dispatch->request, $dispatch, $previousStatus, $before);
        $previousHandover = is_array($before['local_handover_details'] ?? null) ? $before['local_handover_details'] : [];
        $currentHandover = is_array($dispatch->local_handover_details) ? $dispatch->local_handover_details : [];
        if (blank($previousHandover['released_at'] ?? null) && filled($currentHandover['released_at'] ?? null)) {
            $workflowNotifications->notifyLocalHandoverMilestone($dispatch->request, $dispatch, 'released', $request->user()?->id);
        }
        if (blank($previousHandover['received_at'] ?? null) && filled($currentHandover['received_at'] ?? null)) {
            $workflowNotifications->notifyLocalHandoverMilestone($dispatch->request, $dispatch, 'received', $request->user()?->id);
        }

        // Notify DRMD + concerned LGU only when the plan becomes fully confirmed
        // (every vehicle / local handover). Do not fire on the first partial plan.
        $wasTransportPlanComplete = DispatchPlan::transportPlanIsComplete(
            is_array($before['vehicle_details'] ?? null) ? $before['vehicle_details'] : [],
            is_array($before['local_handover_details'] ?? null) ? $before['local_handover_details'] : null,
        );
        if (! $wasTransportPlanComplete && $dispatch->isTransportPlanComplete()) {
            $workflowNotifications->notifyDispatchPlanReadyForRelease(
                $dispatch->request,
                $dispatch,
                $request->user()?->id,
            );
        }

        $this->publishRealtime($realtime, $dispatch, 'dispatch.updated');

        $escortDestination = $escortUpdate || $returnToEscortWorkspace;
        $destinationBucket = $escortDestination
            ? ($dispatch->status === DispatchPlan::STATUS_RECEIVED ? 'completed' : 'in_progress')
            : $dispatch->bucket();

        return redirect()
            ->route($escortDestination ? 'delivery-escort.index' : 'dispatches.index', [
                'bucket' => $destinationBucket,
                'dispatch_id' => $dispatch->id,
            ])
            ->with(
                'success',
                $recordsRelease
                    ? 'The selected transaction was released and its confirmed quantities were deducted from system inventory. Encode the same issuance manually in WIT for reconciliation.'
                    : count($dispatch->resolvedVehicleDetails()) + (filled(data_get($dispatch->local_handover_details, 'dr_number')) ? 1 : 0).' Delivery Receipt(s) are ready. The dispatch plan was updated.',
            );
    }

    public function followUp(
        Request $request,
        DispatchPlan $dispatch,
        AuditLogger $audit,
        WorkflowNotificationService $workflowNotifications,
        RealtimePublisher $realtime,
    ): RedirectResponse {
        $this->authorizeDispatchAccess($request, $dispatch);
        $dispatch->loadMissing(['items', 'request', 'requisitionIssuanceSlip']);

        if ($dispatch->status !== DispatchPlan::STATUS_RECEIVED) {
            return back()->with('error', 'Complete all current transaction receipts before creating the follow-up dispatch and DR.');
        }
        $deferred = $dispatch->items->filter(fn (DispatchPlanItem $item): bool =>
            in_array($item->variance_disposition, DispatchPlan::REPLACEMENT_REQUIRED_DISPOSITIONS, true)
            && (int) $item->allocated_quantity > (int) ($item->received_quantity ?? 0));
        if ($deferred->isEmpty()) {
            return back()->with('error', 'There are no unresolved balances requiring a follow-up delivery.');
        }

        $existingDraft = DispatchPlan::query()
            ->where('parent_dispatch_plan_id', $dispatch->id)
            ->where('status', DispatchPlan::STATUS_DRAFT)
            ->first();
        if ($existingDraft) {
            return redirect()->route('dispatches.index', ['bucket' => 'still_for_action', 'dispatch_id' => $existingDraft->id])
                ->with('success', 'The existing follow-up dispatch draft was opened.');
        }

        $seriesOffset = DispatchPlan::query()
            ->where('requisition_issuance_slip_id', $dispatch->requisition_issuance_slip_id)
            ->get(['vehicle_details'])
            ->flatMap(fn (DispatchPlan $plan) => $plan->resolvedVehicleDetails())
            ->map(fn (array $vehicle): int => $this->drSuffixNumber((string) ($vehicle['dr_number'] ?? '')))
            ->max() ?? 0;
        $sequence = ((int) DispatchPlan::query()
            ->where('requisition_issuance_slip_id', $dispatch->requisition_issuance_slip_id)
            ->max('delivery_sequence')) + 1;

        $followUp = DB::transaction(function () use ($request, $dispatch, $deferred, $seriesOffset, $sequence): DispatchPlan {
            $copy = Arr::only($dispatch->toArray(), [
                'request_id', 'requisition_issuance_slip_id', 'destination', 'receiving_agency_lgu',
                'source_of_goods', 'purpose', 'fulfillment_type',
            ]);
            $plan = DispatchPlan::create([
                ...$copy,
                'parent_dispatch_plan_id' => $dispatch->id,
                'delivery_sequence' => $sequence,
                'dr_series_offset' => $seriesOffset,
                'dispatch_number' => 'DSP-'.now()->format('Ymd').'-'.Str::upper(Str::random(5)),
                'dispatcher' => $this->dispatchOfficerName($request),
                'number_of_vehicles' => 0,
                'vehicles_needed' => false,
                'vehicle_details' => [],
                'status' => DispatchPlan::STATUS_DRAFT,
                'remarks' => 'Follow-up delivery for unresolved deferred/returned/cancelled balances from '.$dispatch->dispatch_number.'.',
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
            ]);
            $plan->appendTimeline(DispatchPlan::STATUS_DRAFT, $request->user()?->id, 'Follow-up dispatch created for deferred delivery balances', null, 'status_change', $request->user()?->name);
            $plan->save();
            $plan->items()->createMany($deferred->map(fn (DispatchPlanItem $item): array => [
                'requisition_issuance_item_id' => $item->requisition_issuance_item_id,
                'request_item_id' => $item->request_item_id,
                'warehouse_id' => $item->warehouse_id,
                'item_name' => $item->item_name,
                'unit' => $item->unit,
                'warehouse_name' => $item->warehouse_name,
                'allocated_quantity' => max(0, (int) $item->allocated_quantity - (int) ($item->received_quantity ?? 0)),
                'remarks' => ucfirst(str_replace('_', ' ', (string) $item->variance_disposition)).' from '.$dispatch->dispatch_number.': '.($item->variance_resolution ?: $item->return_reason),
            ])->all());
            $plan = $plan->fresh(['items', 'request', 'requisitionIssuanceSlip']);

            // Re-evaluate the replacement items instead of inheriting the original
            // trip's transport decision. Returned/cancelled stock that is fulfilled
            // from the receiving LGU's own warehouse is a direct custody handover:
            // it has no vehicle, driver, or escort assignment.
            $sourceData = $this->pruneLocalOnlyTransportVehicles([
                ...$plan->toArray(),
                'items' => $plan->items->map(fn (DispatchPlanItem $item): array => $item->toArray())->all(),
            ]);
            $requiresTransport = $this->classifiedSourceWarehouses($sourceData)
                ->contains(fn (array $warehouse): bool => (bool) ($warehouse['requires_transport'] ?? true));
            $localWarehouse = $this->classifiedSourceWarehouses($sourceData)
                ->first(fn (array $warehouse): bool => ! ($warehouse['requires_transport'] ?? true));

            $plan->forceFill([
                'vehicles_needed' => $requiresTransport,
                'number_of_vehicles' => 0,
                'vehicle_details' => [],
                'local_handover_details' => $localWarehouse ? [
                    'source_warehouse_id' => $localWarehouse['id'] ?? null,
                    'source_warehouse_name' => $localWarehouse['name'] ?? null,
                    'remarks' => $plan->purpose,
                ] : [],
            ])->save();

            return $plan->fresh(['items', 'request', 'requisitionIssuanceSlip']);
        });

        $audit->log('dispatch.follow_up_created', $followUp, [], $followUp->toArray());
        $workflowNotifications->notifyDispatchStatusChanged($followUp->request, $followUp, previousStatus: null);
        $this->publishRealtime($realtime, $followUp, 'dispatch.created');

        return redirect()->route('dispatches.index', ['bucket' => 'still_for_action', 'dispatch_id' => $followUp->id])
            ->with('success', $followUp->vehicles_needed
                ? 'A separate follow-up dispatch was created with only the unresolved replacement quantities. Assign a new vehicle when the schedule is known; the system will generate a different DR number for this trip.'
                : 'A separate no-transport follow-up was created for the unresolved replacement quantities. Confirm the local warehouse release and recipient receipt; no vehicle, driver, or escort is required.');
    }

    private function constrainEscortUpdate(DispatchPlan $dispatch, array $data, User $user): array
    {
        $currentStatus = (string) $dispatch->status;
        $targetStatus = (string) ($data['status'] ?? $currentStatus);
        $allowedTargets = [
            DispatchPlan::STATUS_PLANNED => [DispatchPlan::STATUS_PLANNED, DispatchPlan::STATUS_RELEASED],
            DispatchPlan::STATUS_RELEASED => [DispatchPlan::STATUS_RELEASED, DispatchPlan::STATUS_IN_TRANSIT, DispatchPlan::STATUS_RECEIVED],
            DispatchPlan::STATUS_IN_TRANSIT => [DispatchPlan::STATUS_IN_TRANSIT, DispatchPlan::STATUS_RECEIVED],
            DispatchPlan::STATUS_RECEIVED => [DispatchPlan::STATUS_RECEIVED],
        ];
        abort_unless(
            in_array($targetStatus, $allowedTargets[$currentStatus] ?? [], true),
            403,
            'The assigned escort cannot move the delivery backward or change its planning status.',
        );

        $operationalVehicleKeys = [
            'land_transportation_source',
            'warehouse_released_at',
            'release_witness_affiliation',
            'release_witnessed_by',
            'release_witness_contact_number',
            'release_witness_id_number',
            'release_witness_position',
            'release_witness_office',
            'loaded_at',
            'loading_remarks',
            'loaded_items',
            'departed_at',
            'actual_arrival',
            'delivered_at',
            'fully_delivered',
            'received_by',
            'received_by_id_number',
            'received_by_position',
            'received_by_office',
            'received_at',
            'receiver_contact',
            'receipt_acknowledged',
            'receipt_remarks',
        ];
        $existingVehicles = $dispatch->resolvedVehicleDetails();
        $submittedVehicles = is_array($data['vehicle_details'] ?? null) ? $data['vehicle_details'] : [];
        $allowedIndexes = $this->authorizedVehicleIndexes($dispatch, $user);

        // The escort workspace sends a compact, zero-based list containing only
        // vehicles assigned to the signed-in escort. Translate transaction command
        // indexes back to their persisted vehicle indexes before validation. Without
        // this translation, confirming receipt for a displayed "Vehicle 1" could
        // validate/update the dispatch's actual vehicle 1 instead of (for example)
        // the escort's persisted vehicle 3.
        $sourceIndexBySubmittedIndex = collect($submittedVehicles)
            ->mapWithKeys(fn ($row, $localIndex): array => [
                (int) $localIndex => (int) (is_array($row)
                    ? ($row['source_vehicle_index'] ?? $localIndex)
                    : $localIndex),
            ]);
        foreach ([
            'plan_vehicle_indexes',
            'release_vehicle_indexes',
            'transit_vehicle_indexes',
            'receipt_vehicle_indexes',
        ] as $commandKey) {
            if (! is_array($data[$commandKey] ?? null)) continue;

            $data[$commandKey] = collect($data[$commandKey])
                ->map(fn ($index): int => (int) $sourceIndexBySubmittedIndex->get((int) $index, (int) $index))
                ->filter(fn (int $index): bool => in_array($index, $allowedIndexes, true))
                ->unique()
                ->values()
                ->all();
        }
        $data['vehicle_details'] = collect($existingVehicles)
            ->map(function (array $vehicle, int $index) use ($submittedVehicles, $operationalVehicleKeys, $allowedIndexes): array {
                if (! in_array($index, $allowedIndexes, true)) return $vehicle;
                $submitted = collect($submittedVehicles)->first(
                    fn ($row, $localIndex): bool => (int) ($row['source_vehicle_index'] ?? $localIndex) === $index,
                    []
                );
                return [...$vehicle, ...Arr::only(is_array($submitted) ? $submitted : [], $operationalVehicleKeys)];
            })
            ->values()
            ->all();
        $data['number_of_vehicles'] = count($data['vehicle_details']);

        // A scoped user receives only their vehicle's cargo rows. Merge permitted receipt
        // fields back into the complete item set so saving one truck cannot erase or alter
        // another truck's allocation.
        $allowedItemIds = collect($existingVehicles)
            ->only($allowedIndexes)
            ->flatMap(fn (array $vehicle) => collect($vehicle['loaded_items'] ?? [])->pluck('requisition_issuance_item_id'))
            ->filter()->map(fn ($id) => (string) $id)->unique();
        $submittedItems = collect(is_array($data['items'] ?? null) ? $data['items'] : [])
            ->keyBy(fn (array $item) => (string) ($item['requisition_issuance_item_id'] ?? ''));
        $data['items'] = $dispatch->items()->get()->map(function (DispatchPlanItem $item) use ($allowedItemIds, $submittedItems): array {
            $current = $item->toArray();
            $id = (string) $item->requisition_issuance_item_id;
            if (! $allowedItemIds->contains($id)) return $current;
            return [...$current, ...Arr::only($submittedItems->get($id, []), [
                'received_quantity', 'variance_disposition', 'variance_resolution',
                'return_condition', 'return_stock_disposition', 'return_received_at',
                'return_inspected_by', 'return_reason',
            ])];
        })->all();

        foreach ([
            'destination', 'receiving_agency_lgu', 'fulfillment_type', 'vehicle_id',
            'driver', 'driver_contact_number', 'vehicle_plate_number', 'vehicle_types',
            'mode_of_transportation', 'dispatch_date', 'estimated_arrival',
            'source_of_goods', 'purpose',
        ] as $field) {
            $data[$field] = $dispatch->{$field};
        }

        return $data;
    }

    /**
     * Quick-add a Vehicle Type library value while planning a dispatch.
     * Persists to the same operational library used by Libraries / inventory forms.
     */
    public function storeVehicleType(Request $request): JsonResponse
    {
        $this->authorizeDispatchAccess($request);

        $value = preg_replace('/\s+/u', ' ', trim((string) $request->input('value', ''))) ?? '';
        $request->merge([
            'value' => $value,
            'is_active' => $request->boolean('is_active', true),
        ]);

        $data = $request->validate([
            'value' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $duplicate = OperationalLibraryValue::query()
            ->where('library_type', 'vehicle_type')
            ->get()
            ->contains(function (OperationalLibraryValue $row) use ($data): bool {
                $existing = preg_replace('/\s+/u', ' ', trim((string) $row->value)) ?? '';

                return mb_strtolower($existing) === mb_strtolower($data['value']);
            });

        if ($duplicate) {
            throw ValidationException::withMessages([
                'value' => 'This library value already exists.',
            ]);
        }

        $entry = OperationalLibraryValue::create([
            'library_type' => 'vehicle_type',
            'value' => $data['value'],
            'context' => 'all',
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        return response()->json([
            'message' => 'Vehicle type added.',
            'value' => $entry->value,
            'label' => $entry->value,
            'id' => $entry->id,
            'is_active' => (bool) $entry->is_active,
        ], 201);
    }

    public function storeLandTransportationSource(Request $request): JsonResponse
    {
        $this->authorizeDispatchAccess($request);
        $value = preg_replace('/\s+/u', ' ', trim((string) $request->input('value', ''))) ?? '';
        $data = validator(['value' => $value], ['value' => ['required', 'string', 'max:255']])->validate();
        $normalized = OperationalLibraryValue::normalizeLibraryName($data['value']);
        $existing = OperationalLibraryValue::query()->where('library_type', 'transportation_source')->where('context', 'land')->get()
            ->first(fn (OperationalLibraryValue $row): bool => OperationalLibraryValue::normalizeLibraryName($row->value) === $normalized);
        if ($existing) {
            return response()->json(['message' => 'Selected existing land transportation source.', 'value' => $existing->value]);
        }
        $entry = OperationalLibraryValue::create([
            'library_type' => 'transportation_source', 'value' => $data['value'], 'context' => 'land',
            'metadata' => ['source' => 'dispatch_quick_add'], 'is_active' => true,
        ]);

        return response()->json(['message' => 'Land transportation source added.', 'value' => $entry->value], 201);
    }

    public function storeWitClassificationOption(Request $request): JsonResponse
    {
        $this->authorizeDispatchAccess($request);
        $data = $request->validate([
            'library_type' => ['required', Rule::in(['source_of_goods', 'transaction_purpose'])],
            'value' => ['required', 'string', 'max:255'],
        ]);
        $value = preg_replace('/\s+/u', ' ', trim((string) $data['value'])) ?? '';
        $normalized = OperationalLibraryValue::normalizeLibraryName($value);
        $existing = OperationalLibraryValue::query()
            ->where('library_type', $data['library_type'])
            ->get()
            ->first(fn (OperationalLibraryValue $row): bool => OperationalLibraryValue::normalizeLibraryName($row->value) === $normalized);

        if ($existing) {
            return response()->json(['message' => 'Selected existing option.', 'value' => $existing->value]);
        }

        $entry = OperationalLibraryValue::create([
            'library_type' => $data['library_type'],
            'value' => $value,
            'context' => 'wit_dropdown',
            'metadata' => ['source' => 'dispatch_quick_add'],
            'is_active' => true,
        ]);

        return response()->json(['message' => 'Option added.', 'value' => $entry->value], 201);
    }

    /**
     * Quick-add a Driver (Transported By) library entry while planning a dispatch.
     */
    public function storeDriver(Request $request): JsonResponse
    {
        $this->authorizeDispatchAccess($request);

        $name = preg_replace('/\s+/u', ' ', trim((string) $request->input('name', $request->input('value', '')))) ?? '';
        $contact = preg_replace('/\s+/u', ' ', trim((string) $request->input('contact_number', ''))) ?? '';
        $position = preg_replace('/\s+/u', ' ', trim((string) $request->input('position', ''))) ?? '';
        $office = preg_replace('/\s+/u', ' ', trim((string) $request->input('office', ''))) ?? '';
        $idNumber = preg_replace('/\s+/u', ' ', trim((string) $request->input('id_number', ''))) ?? '';
        $request->merge([
            'name' => $name,
            'contact_number' => $contact,
            'position' => $position !== '' ? $position : null,
            'office' => $office !== '' ? $office : null,
            'id_number' => $idNumber !== '' ? $idNumber : null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_number' => ['required', 'string', 'max:80'],
            'position' => ['nullable', 'string', 'max:255'],
            'office' => ['nullable', 'string', 'max:255'],
            'id_number' => ['nullable', 'string', 'max:80'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $normalized = OperationalLibraryValue::normalizeLibraryName($data['name']);
        $duplicate = OperationalLibraryValue::query()
            ->where('library_type', 'dispatch_driver')
            ->get()
            ->first(fn (OperationalLibraryValue $row): bool => OperationalLibraryValue::normalizeLibraryName($row->value) === $normalized);

        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => 'This driver already exists in the library.',
            ]);
        }

        $metadata = array_filter([
            'contact_number' => $data['contact_number'] ?? null,
            'position' => $data['position'] ?? null,
            'office' => $data['office'] ?? null,
            'id_number' => $data['id_number'] ?? null,
        ], fn ($value) => filled($value));

        $entry = OperationalLibraryValue::create([
            'library_type' => 'dispatch_driver',
            'value' => $data['name'],
            'context' => 'all',
            'metadata' => $metadata === [] ? null : $metadata,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        return response()->json([
            'message' => 'Driver added.',
            'id' => $entry->id,
            'value' => $entry->value,
            'label' => $entry->value,
            'contact_number' => data_get($entry->metadata, 'contact_number'),
            'position' => data_get($entry->metadata, 'position'),
            'office' => data_get($entry->metadata, 'office'),
            'id_number' => data_get($entry->metadata, 'id_number'),
            'metadata' => $entry->metadata ?? [],
            'is_active' => (bool) $entry->is_active,
        ], 201);
    }

    /**
     * Quick-add a Received By library entry while planning a dispatch.
     */
    public function storeReceivedBy(Request $request): JsonResponse
    {
        $this->authorizeDispatchAccess($request);

        $name = preg_replace('/\s+/u', ' ', trim((string) $request->input('name', $request->input('value', '')))) ?? '';
        $position = preg_replace('/\s+/u', ' ', trim((string) $request->input('position', ''))) ?? '';
        $office = preg_replace('/\s+/u', ' ', trim((string) $request->input('office', ''))) ?? '';
        $request->merge([
            'name' => $name,
            'position' => $position !== '' ? $position : null,
            'office' => $office !== '' ? $office : null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'office' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $normalized = OperationalLibraryValue::normalizeLibraryName($data['name']);
        $duplicate = OperationalLibraryValue::query()
            ->where('library_type', 'dispatch_received_by')
            ->get()
            ->first(fn (OperationalLibraryValue $row): bool => OperationalLibraryValue::normalizeLibraryName($row->value) === $normalized);

        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => 'This person already exists in the Received By library.',
            ]);
        }

        $metadata = [
            'position' => $data['position'] ?? '',
            'office' => $data['office'] ?? '',
        ];

        $entry = OperationalLibraryValue::create([
            'library_type' => 'dispatch_received_by',
            'value' => $data['name'],
            'context' => 'all',
            'metadata' => $metadata,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        return response()->json([
            'message' => 'Received By person added.',
            'id' => $entry->id,
            'value' => $entry->value,
            'label' => $entry->value,
            'position' => data_get($entry->metadata, 'position'),
            'office' => data_get($entry->metadata, 'office'),
            'metadata' => $entry->metadata ?? [],
            'is_active' => (bool) $entry->is_active,
        ], 201);
    }

    private function authorizeDispatchAccess(
        Request $request,
        ?DispatchPlan $dispatch = null,
        bool $escortWorkspace = false,
    ): void {
        $user = $request->user();
        abort_unless($user, 403);

        if ($this->canManageDispatches($user)) {
            return;
        }

        abort_unless($this->isDswdEmployee($user), 403);

        if ($escortWorkspace && $dispatch === null) {
            return;
        }

        abort_unless(
            $dispatch
            && in_array((string) $dispatch->status, [
                DispatchPlan::STATUS_PLANNED,
                DispatchPlan::STATUS_RELEASED,
                DispatchPlan::STATUS_IN_TRANSIT,
                DispatchPlan::STATUS_RECEIVED,
            ], true)
            && ($this->isAssignedEscort($dispatch, $user) || $this->isNearbyStorekeeper($dispatch, $user)),
            403,
            'Only the assigned DSWD escort, dispatch officer, or nearby DSWD storekeeper may update this delivery transaction.',
        );
    }

    private function canManageDispatches(?User $user): bool
    {
        return (bool) ($user
            && ($user->hasAnyRole(['Super Admin', 'RROS', 'RROS AA']) || $user->can('manage dispatches')));
    }

    private function isDswdEmployee(User $user): bool
    {
        return (bool) $user->is_active
            && ! $user->hasRole('LGU')
            && blank($user->lgu_psgc_code)
            && blank($user->lgu_name);
    }

    private function isNearbyStorekeeper(DispatchPlan $dispatch, User $user): bool
    {
        $work = Str::lower(implode(' ', array_filter([$user->position, $user->designation, $user->office])));
        if (! Str::contains($work, ['storekeeper', 'warehouse'])) return false;
        $area = Str::lower(implode(' ', array_filter([$user->area_of_assignment, $user->office])));
        $warehouses = collect($dispatch->resolvedVehicleDetails())->pluck('source_warehouse_id')->filter()->unique();
        if ($warehouses->isEmpty()) return false;
        return Warehouse::query()->whereIn('id', $warehouses)->get()->contains(function (Warehouse $warehouse) use ($area): bool {
            if ($area === '') return false;
            return collect([$warehouse->name, $warehouse->municipality, $warehouse->province])
                ->filter()->contains(fn ($value): bool => Str::contains($area, Str::lower(trim((string) $value))));
        });
    }

    private function isAssignedEscort(DispatchPlan $dispatch, User $user): bool
    {
        $userIdNumber = Str::lower(trim((string) $user->id_number));
        $userName = Str::lower(preg_replace('/\s+/u', ' ', trim((string) $user->name)) ?? '');

        return collect($dispatch->resolvedVehicleDetails())->contains(function (array $vehicle) use ($userIdNumber, $userName): bool {
            if (! (bool) ($vehicle['has_dswd_escort'] ?? false)) {
                return false;
            }

            $escortId = Str::lower(trim((string) ($vehicle['escort_id_number'] ?? '')));
            $escortName = Str::lower(preg_replace('/\s+/u', ' ', trim((string) ($vehicle['escort_name'] ?? ''))) ?? '');

            return ($userIdNumber !== '' && $escortId !== '' && hash_equals($userIdNumber, $escortId))
                || ($userName !== '' && $escortName !== '' && hash_equals($userName, $escortName));
        });
    }

    /** @return list<int> Original indexes of vehicles this non-manager may access. */
    private function authorizedVehicleIndexes(DispatchPlan $dispatch, User $user): array
    {
        if ($this->canManageDispatches($user)) {
            return array_keys($dispatch->resolvedVehicleDetails());
        }

        $userIdNumber = Str::lower(trim((string) $user->id_number));
        $userName = Str::lower(preg_replace('/\s+/u', ' ', trim((string) $user->name)) ?? '');
        $area = Str::lower(implode(' ', array_filter([$user->area_of_assignment, $user->office])));
        $isStorekeeper = Str::contains(
            Str::lower(implode(' ', array_filter([$user->position, $user->designation, $user->office]))),
            ['storekeeper', 'warehouse']
        );
        $warehouseIds = collect($dispatch->resolvedVehicleDetails())->pluck('source_warehouse_id')->filter()->unique();
        $warehouses = $isStorekeeper
            ? Warehouse::query()->whereIn('id', $warehouseIds)->get()->keyBy(fn (Warehouse $warehouse) => (string) $warehouse->id)
            : collect();

        return collect($dispatch->resolvedVehicleDetails())
            ->filter(function (array $vehicle) use ($userIdNumber, $userName, $area, $warehouses): bool {
                $escortId = Str::lower(trim((string) ($vehicle['escort_id_number'] ?? '')));
                $escortName = Str::lower(preg_replace('/\s+/u', ' ', trim((string) ($vehicle['escort_name'] ?? ''))) ?? '');
                $escortMatch = (bool) ($vehicle['has_dswd_escort'] ?? false) && (
                    ($userIdNumber !== '' && $escortId !== '' && hash_equals($userIdNumber, $escortId))
                    || ($userName !== '' && $escortName !== '' && hash_equals($userName, $escortName))
                );
                $warehouse = $warehouses->get((string) ($vehicle['source_warehouse_id'] ?? ''));
                $warehouseMatch = $warehouse && $area !== '' && collect([$warehouse->name, $warehouse->municipality, $warehouse->province])
                    ->filter()->contains(fn ($value): bool => Str::contains($area, Str::lower(trim((string) $value))));
                return $escortMatch || $warehouseMatch;
            })
            ->keys()->map(fn ($index): int => (int) $index)->values()->all();
    }

    private function authorizeVehicleAccess(User $user, DispatchPlan $dispatch, int $vehicleIndex): void
    {
        if ($this->deliveryUpdateDevelopmentAccess()) return;
        if ($this->canManageDispatches($user)) return;
        abort_unless(
            in_array($vehicleIndex, $this->authorizedVehicleIndexes($dispatch, $user), true),
            403,
            'You may only access the vehicle assigned to you or your assigned warehouse.'
        );
    }

    /** @return list<int> */
    private function assignedEscortDispatchIds(User $user): array
    {
        return DispatchPlan::query()
            ->whereIn('status', [
                DispatchPlan::STATUS_PLANNED,
                DispatchPlan::STATUS_RELEASED,
                DispatchPlan::STATUS_IN_TRANSIT,
                DispatchPlan::STATUS_RECEIVED,
            ])
            ->get(['id', 'vehicle_details', 'status'])
            ->filter(fn (DispatchPlan $dispatch): bool => $this->isAssignedEscort($dispatch, $user))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function validatedPayload(Request $request, bool $creating, ?DispatchPlan $existing = null): array
    {
        $modeOptions = ['DSWD-Owned', 'Service Provider', 'Government Asset', 'Partner', 'Partner LGU'];

        $nullableEmpty = [];
        foreach ([
            'fully_delivered',
            'has_returned_items',
            'returned_particulars',
            'returned_quantity',
            'returned_reason',
            'delivered_at',
            'release_witnessed_by',
        ] as $key) {
            if ($request->exists($key) && $request->input($key) === '') {
                $nullableEmpty[$key] = null;
            }
        }
        if ($nullableEmpty !== []) {
            $request->merge($nullableEmpty);
        }

        // Per-vehicle mode is a single choice; coerce legacy array/CSV payloads before validate.
        $vehicleDetailsInput = $request->input('vehicle_details');
        if (is_array($vehicleDetailsInput)) {
            foreach ($vehicleDetailsInput as $index => $row) {
                if (! is_array($row) || ! array_key_exists('mode_of_transportation', $row)) {
                    continue;
                }
                $modes = $this->asStringList($row['mode_of_transportation']);
                $mode = $modes[0] ?? null;
                if ($mode === 'Partner LGU') {
                    $mode = 'Partner';
                }
                $vehicleDetailsInput[$index]['mode_of_transportation'] = $mode;
            }
            $request->merge(['vehicle_details' => $vehicleDetailsInput]);
        }

        $rules = [
            'request_id' => [$creating ? 'required' : 'nullable', 'exists:requests,id'],
            'vehicle_id' => ['nullable'], // Cleared on save; fleet linkage is not used in dispatch planning.
            'vehicles_needed' => ['nullable', Rule::in(['yes', 'no', true, false, 1, 0, '1', '0'])],
            'destination' => ['nullable', 'string', 'max:255'],
            'receiving_agency_lgu' => ['nullable', 'string', 'max:255'],
            'source_of_goods' => ['nullable', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'driver' => ['nullable', 'string', 'max:255'],
            'driver_contact_number' => ['nullable', 'string', 'max:80'],
            'vehicle_plate_number' => ['nullable', 'string', 'max:80'],
            'dispatcher' => ['nullable', 'string', 'max:255'],
            'dispatch_officer' => ['nullable', 'string', 'max:255'],
            'mode_of_transportation' => ['nullable', 'array'],
            'mode_of_transportation.*' => ['string', Rule::in($modeOptions)],
            'vehicle_types' => ['nullable', 'array'],
            'vehicle_types.*' => ['string', 'max:120'],
            'number_of_vehicles' => ['nullable', 'integer', 'min:0', 'max:99'],
            'vehicle_details' => ['nullable', 'array', 'max:99'],
            'vehicle_details.*.vehicle_type' => ['nullable', 'string', 'max:120'],
            'vehicle_details.*.source_vehicle_index' => ['nullable', 'integer', 'min:0'],
            'vehicle_details.*.dr_number' => ['nullable', 'string', 'max:120'],
            'vehicle_details.*.source_warehouse_id' => ['nullable', 'integer'],
            'vehicle_details.*.source_warehouse_name' => ['nullable', 'string', 'max:255'],
            'vehicle_details.*.source_warehouses' => ['nullable', 'array'],
            'vehicle_details.*.source_warehouses.*.id' => ['nullable', 'integer'],
            'vehicle_details.*.source_warehouses.*.name' => ['nullable', 'string', 'max:255'],
            'vehicle_details.*.fulfillment_type' => ['nullable', Rule::in(DispatchPlan::FULFILLMENT_TYPES)],
            'vehicle_details.*.plan_confirmed_at' => ['nullable', 'date'],
            'vehicle_details.*.driver' => ['nullable', 'string', 'max:255'],
            'vehicle_details.*.driver_contact_number' => ['nullable', 'string', 'max:80'],
            'vehicle_details.*.driver_id_number' => ['nullable', 'string', 'max:80'],
            'vehicle_details.*.driver_position' => ['nullable', 'string', 'max:255'],
            'vehicle_details.*.driver_office' => ['nullable', 'string', 'max:255'],
            'vehicle_details.*.vehicle_plate_number' => ['nullable', 'string', 'max:80'],
            'vehicle_details.*.has_dswd_escort' => ['nullable', 'boolean'],
            'vehicle_details.*.escort_name' => ['nullable', 'string', 'max:255'],
            'vehicle_details.*.escort_contact_number' => ['nullable', 'string', 'max:80'],
            'vehicle_details.*.escort_id_number' => ['nullable', 'string', 'max:80'],
            'vehicle_details.*.escort_position' => ['nullable', 'string', 'max:255'],
            'vehicle_details.*.escort_office' => ['nullable', 'string', 'max:255'],
            'vehicle_details.*.estimated_departure' => ['nullable', 'date'],
            'vehicle_details.*.estimated_arrival' => ['nullable', 'date'],
            'vehicle_details.*.planning_remarks' => ['nullable', 'string', 'max:2000'],
            'vehicle_details.*.allows_multi_day_run' => ['nullable', 'boolean'],
            'vehicle_details.*.mode_of_transportation' => ['nullable', 'string', Rule::in($modeOptions)],
            'vehicle_details.*.land_transportation_source' => ['nullable', 'string', 'max:255'],
            'vehicle_details.*.warehouse_released_at' => ['nullable', 'date'],
            'vehicle_details.*.warehouse_released_by' => ['nullable', 'string', 'max:255'],
            'vehicle_details.*.release_authorized_by' => ['nullable', 'string', 'max:255'],
            'vehicle_details.*.release_authorizer_position' => ['nullable', 'string', 'max:255'],
            'vehicle_details.*.release_authorizer_office' => ['nullable', 'string', 'max:255'],
            'vehicle_details.*.release_witnessed_by' => ['nullable', 'string', 'max:255'],
            'vehicle_details.*.release_witness_affiliation' => ['nullable', Rule::in(['dswd', 'lgu'])],
            'vehicle_details.*.release_witness_contact_number' => ['nullable', 'string', 'max:80'],
            'vehicle_details.*.release_witness_id_number' => ['nullable', 'string', 'max:80'],
            'vehicle_details.*.release_witness_position' => ['nullable', 'string', 'max:255'],
            'vehicle_details.*.release_witness_office' => ['nullable', 'string', 'max:255'],
            'vehicle_details.*.loaded_at' => ['nullable', 'date'],
            'vehicle_details.*.loading_remarks' => ['nullable', 'string'],
            'vehicle_details.*.loaded_items' => ['nullable', 'array'],
            'vehicle_details.*.loaded_items.*.requisition_issuance_item_id' => ['nullable', 'integer'],
            'vehicle_details.*.loaded_items.*.item_name' => ['nullable', 'string', 'max:255'],
            'vehicle_details.*.loaded_items.*.loaded_quantity' => ['nullable', 'integer', 'min:0'],
            'vehicle_details.*.loaded_items.*.planned_quantity' => ['nullable', 'integer', 'min:0'],
            'vehicle_details.*.loaded_items.*.remarks' => ['nullable', 'string'],
            'vehicle_details.*.departed_at' => ['nullable', 'date'],
            'vehicle_details.*.actual_arrival' => ['nullable', 'date'],
            'vehicle_details.*.delivered_at' => ['nullable', 'date'],
            'vehicle_details.*.fully_delivered' => ['nullable', Rule::in(['Yes', 'No', true, false, 1, 0, '1', '0'])],
            'vehicle_details.*.received_by' => ['nullable', 'string', 'max:255'],
            'vehicle_details.*.received_by_id_number' => ['nullable', 'string', 'max:80'],
            'vehicle_details.*.recipient_id_number' => ['nullable', 'string', 'max:80'],
            'vehicle_details.*.received_by_position' => ['nullable', 'string', 'max:255'],
            'vehicle_details.*.received_by_office' => ['nullable', 'string', 'max:255'],
            'vehicle_details.*.received_at' => ['nullable', 'date'],
            'vehicle_details.*.receiver_contact' => ['nullable', 'string', 'max:80'],
            'vehicle_details.*.receipt_acknowledged' => ['nullable', 'boolean'],
            'vehicle_details.*.receipt_remarks' => ['nullable', 'string'],
            // Legacy plan-level mirrors (filled from vehicles[0] on normalize).
            'dispatch_date' => ['nullable', 'date'],
            'estimated_arrival' => ['nullable', 'date'],
            'actual_arrival' => ['nullable', 'date'],
            'warehouse_released_at' => ['nullable', 'date'],
            'warehouse_released_by' => ['nullable', 'string', 'max:255'],
            'loaded_at' => ['nullable', 'date'],
            'loading_remarks' => ['nullable', 'string'],
            'departed_at' => ['nullable', 'date'],
            'received_by' => ['nullable', 'string', 'max:255'],
            'received_at' => ['nullable', 'date'],
            'receiver_contact' => ['nullable', 'string', 'max:80'],
            'receipt_acknowledged' => ['nullable', 'boolean'],
            'receipt_remarks' => ['nullable', 'string'],
            'delivered_at' => ['nullable', 'date'],
            'release_witnessed_by' => ['nullable', 'string', 'max:255'],
            'fully_delivered' => ['nullable', Rule::in(['Yes', 'No', true, false, 1, 0, '1', '0'])],
            'has_returned_items' => ['nullable', Rule::in(['Yes', 'No', true, false, 1, 0, '1', '0'])],
            'returned_particulars' => ['nullable', 'string', 'max:1000'],
            'returned_quantity' => ['nullable', 'integer', 'min:0'],
            'returned_reason' => ['nullable', 'string', 'max:2000'],
            'remarks' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(DispatchPlan::STATUSES)],
            'release_vehicle_indexes' => ['nullable', 'array'],
            'release_vehicle_indexes.*' => ['integer', 'min:0'],
            'release_local_handover' => ['nullable', 'boolean'],
            'update_local_receipt' => ['nullable', 'boolean'],
            'plan_vehicle_indexes' => ['nullable', 'array'],
            'plan_vehicle_indexes.*' => ['integer', 'min:0'],
            'plan_local_handover' => ['nullable', 'boolean'],
            'receipt_vehicle_indexes' => ['nullable', 'array'],
            'receipt_vehicle_indexes.*' => ['integer', 'min:0'],
            'transit_vehicle_indexes' => ['nullable', 'array'],
            'transit_vehicle_indexes.*' => ['integer', 'min:0'],
            'return_to_escort_workspace' => ['nullable', 'boolean'],
            'fulfillment_type' => ['nullable', Rule::in(DispatchPlan::FULFILLMENT_TYPES)],
            'fulfillment_type_confirmed' => ['nullable', 'boolean'],
            'local_handover_details' => ['nullable', 'array'],
            'local_handover_details.source_warehouse_id' => ['nullable', 'integer'],
            'local_handover_details.source_warehouse_name' => ['nullable', 'string', 'max:255'],
            'local_handover_details.plan_confirmed_at' => ['nullable', 'date'],
            'local_handover_details.dr_number' => ['nullable', 'string', 'max:120'],
            'local_handover_details.expected_release_at' => ['nullable', 'date'],
            'local_handover_details.planning_remarks' => ['nullable', 'string', 'max:2000'],
            'local_handover_details.release_authorized_by' => ['nullable', 'string', 'max:255'],
            'local_handover_details.release_authorizer_position' => ['nullable', 'string', 'max:255'],
            'local_handover_details.release_authorizer_office' => ['nullable', 'string', 'max:255'],
            'local_handover_details.released_by' => ['nullable', 'string', 'max:255'],
            'local_handover_details.release_witness_affiliation' => ['nullable', Rule::in(['dswd', 'lgu'])],
            'local_handover_details.releaser_id_number' => ['nullable', 'string', 'max:80'],
            'local_handover_details.releaser_position' => ['nullable', 'string', 'max:255'],
            'local_handover_details.releaser_office' => ['nullable', 'string', 'max:255'],
            'local_handover_details.releaser_contact' => ['nullable', 'string', 'max:80'],
            'local_handover_details.released_at' => ['nullable', 'date'],
            'local_handover_details.received_by' => ['nullable', 'string', 'max:255'],
            'local_handover_details.receiver_id_number' => ['nullable', 'string', 'max:80'],
            'local_handover_details.receiver_position' => ['nullable', 'string', 'max:255'],
            'local_handover_details.receiver_office' => ['nullable', 'string', 'max:255'],
            'local_handover_details.receiver_contact' => ['nullable', 'string', 'max:80'],
            'local_handover_details.received_at' => ['nullable', 'date'],
            'local_handover_details.receipt_acknowledged' => ['nullable', 'boolean'],
            'local_handover_details.remarks' => ['nullable', 'string', 'max:2000'],
            'items' => ['nullable', 'array'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.requisition_issuance_item_id' => ['nullable', 'integer'],
            'items.*.request_item_id' => ['nullable', 'integer'],
            'items.*.warehouse_id' => ['nullable', 'integer'],
            'items.*.item_name' => ['required_with:items', 'string', 'max:255'],
            'items.*.unit' => ['nullable', 'string', 'max:80'],
            'items.*.warehouse_name' => ['nullable', 'string', 'max:255'],
            'items.*.allocated_quantity' => ['nullable', 'integer', 'min:0'],
            'items.*.loaded_quantity' => ['nullable', 'integer', 'min:0'],
            'items.*.received_quantity' => ['nullable', 'integer', 'min:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.variance_disposition' => ['nullable', Rule::in(['deferred', 'returned', 'cancelled', 'lost_damaged', 'other', 'fulfilled_followup'])],
            'items.*.variance_resolution' => ['nullable', 'string', 'max:2000'],
            'items.*.return_condition' => ['nullable', Rule::in(['serviceable', 'near_expiry', 'damaged', 'expired'])],
            'items.*.return_stock_disposition' => ['nullable', Rule::in(['restock_available', 'restricted_priority', 'quarantine_disposal'])],
            'items.*.return_received_at' => ['nullable', 'date'],
            'items.*.return_inspected_by' => ['nullable', 'string', 'max:255'],
            'items.*.return_reason' => ['nullable', 'string', 'max:2000'],
            'items.*.remarks' => ['nullable', 'string'],
        ];

        $data = $request->validate($rules);
        if (blank($data['source_of_goods'] ?? null)
            && (filled($data['request_id'] ?? $existing?->request_id)
                || filled($data['requisition_issuance_slip_id'] ?? $existing?->requisition_issuance_slip_id))) {
            $data['source_of_goods'] = 'FO Stockpile/Prepo';
        }
        $vehicleArrangementErrors = [];
        foreach ($data['plan_vehicle_indexes'] ?? [] as $vehicleIndex) {
            $vehicleIndex = (int) $vehicleIndex;
            $vehicleRow = data_get($data, "vehicle_details.$vehicleIndex");
            if (! is_array($vehicleRow)) {
                $vehicleRow = collect($data['vehicle_details'] ?? [])
                    ->first(function ($row, $localIndex) use ($vehicleIndex): bool {
                        if (! is_array($row)) {
                            return false;
                        }

                        return (int) ($row['source_vehicle_index'] ?? $localIndex) === $vehicleIndex;
                    }, []);
            }
            if (blank(data_get($vehicleRow, 'fulfillment_type'))) {
                $vehicleArrangementErrors["vehicle_details.$vehicleIndex.fulfillment_type"] =
                    'Select DSWD delivery or Partner/recipient pickup for this vehicle.';
            }
        }
        if ($vehicleArrangementErrors !== []) {
            throw ValidationException::withMessages($vehicleArrangementErrors);
        }
        // These identifiers may be typed manually for an LGU-held/direct
        // release. Keep the submitted values on the canonical handover record;
        // they must not depend on the transport-vehicle receipt aliases.
        if (isset($data['local_handover_details']) && is_array($data['local_handover_details'])) {
            foreach (['releaser_id_number', 'receiver_id_number'] as $idField) {
                if (array_key_exists($idField, $data['local_handover_details'])) {
                    $value = trim((string) $data['local_handover_details'][$idField]);
                    $data['local_handover_details'][$idField] = $value !== '' ? $value : null;
                }
            }
        }
        if (array_key_exists('vehicles_needed', $data)) {
            $data['vehicles_needed'] = in_array($data['vehicles_needed'], ['yes', true, 1, '1'], true);
        }
        // The interactive editor always sends this flag. Older integrations and
        // test fixtures predate the explicit confirmation step; a supplied
        // delivery mode remains an intentional selection for those callers.
        if (! array_key_exists('fulfillment_type_confirmed', $data)) {
            $data['fulfillment_type_confirmed'] = true;
        }

        // Preserve existing saved/test records that predate explicit variance
        // disposition: a legacy return explanation means "returned to warehouse".
        if (isset($data['items']) && is_array($data['items'])) {
            $data['items'] = collect($data['items'])->map(function (array $item): array {
                if (blank($item['variance_disposition'] ?? null) && filled($item['return_reason'] ?? null)) {
                    $item['variance_disposition'] = 'returned';
                }
                if (blank($item['variance_resolution'] ?? null) && filled($item['return_reason'] ?? null)) {
                    $item['variance_resolution'] = $item['return_reason'];
                }
                return $item;
            })->all();
        }

        $data['fulfillment_type'] = $data['fulfillment_type']
            ?? $existing?->fulfillment_type
            ?? DispatchPlan::FULFILLMENT_FIELD_DELIVERY;
        if (! in_array($data['fulfillment_type'], DispatchPlan::FULFILLMENT_TYPES, true)) {
            $data['fulfillment_type'] = DispatchPlan::FULFILLMENT_FIELD_DELIVERY;
        }
        $defaultFulfillmentType = $data['fulfillment_type'];
        $receiptIndexSet = array_fill_keys(array_map('intval', $data['receipt_vehicle_indexes'] ?? []), true);
        $confirmingAllReceipts = ($data['status'] ?? null) === DispatchPlan::STATUS_RECEIVED
            && $receiptIndexSet === [];
        $data['vehicle_details'] = collect($data['vehicle_details'] ?? [])->map(function (array $vehicle, int $index) use ($defaultFulfillmentType, $confirmingAllReceipts, $receiptIndexSet): array {
            $hasConfirmedVehicleWorkflow = filled($vehicle['plan_confirmed_at'] ?? null)
                || filled($vehicle['warehouse_released_at'] ?? null)
                || filled($vehicle['received_at'] ?? null);
            $explicitFulfillmentType = in_array($vehicle['fulfillment_type'] ?? null, DispatchPlan::FULFILLMENT_TYPES, true)
                ? $vehicle['fulfillment_type']
                : null;
            // Never turn an unconfirmed vehicle's blank arrangement into the
            // dispatch-level legacy default during an unrelated save (such as a
            // direct/no-transport plan confirmation).
            $vehicle['fulfillment_type'] = $explicitFulfillmentType
                ?? ($hasConfirmedVehicleWorkflow ? $defaultFulfillmentType : null);
            $effectiveFulfillmentType = $vehicle['fulfillment_type'] ?? $defaultFulfillmentType;
            if ($effectiveFulfillmentType === DispatchPlan::FULFILLMENT_WAREHOUSE_PICKUP) {
                $vehicle['mode_of_transportation'] = 'Partner';
                $confirmingThisReceipt = $confirmingAllReceipts || isset($receiptIndexSet[$index]);
                if ($confirmingThisReceipt && blank($vehicle['warehouse_released_at'] ?? null)) {
                    $vehicle['warehouse_released_at'] = now()->format('Y-m-d H:i:s');
                }
                if ($confirmingThisReceipt && filled($vehicle['warehouse_released_at'] ?? null)) {
                    $vehicle['received_at'] = $vehicle['warehouse_released_at'];
                }
            }

            return $vehicle;
        })->all();
        if ($data['fulfillment_type'] === DispatchPlan::FULFILLMENT_WAREHOUSE_PICKUP) {
            $data['mode_of_transportation'] = ['Partner'];
        }

        $confirmingLocalRelease = (bool) ($data['release_local_handover'] ?? false)
            || (($data['status'] ?? null) === DispatchPlan::STATUS_RECEIVED);
        if ($confirmingLocalRelease && blank(data_get($data, 'local_handover_details.released_at'))) {
            $data['local_handover_details']['released_at'] = now()->format('Y-m-d H:i:s');
        }
        if (filled(data_get($data, 'local_handover_details.released_at'))) {
            $data['local_handover_details']['received_at'] = $data['local_handover_details']['released_at'];
        }

        if (isset($data['mode_of_transportation'])) {
            $data['mode_of_transportation'] = collect($data['mode_of_transportation'])
                ->map(fn ($value) => $value === 'Partner LGU' ? 'Partner' : $value)
                ->unique()
                ->values()
                ->all();
        }

        if (array_key_exists('receipt_acknowledged', $data)) {
            $data['receipt_acknowledged'] = (bool) $data['receipt_acknowledged'];
        }

        foreach (['fully_delivered', 'has_returned_items'] as $yesNoField) {
            if (array_key_exists($yesNoField, $data)) {
                $data[$yesNoField] = $this->normalizeYesNo($data[$yesNoField]);
            }
        }

        if (array_key_exists('returned_quantity', $data) && $data['returned_quantity'] === '') {
            $data['returned_quantity'] = null;
        }

        // Returned/cancelled details are derived from the item outcomes captured
        // during release/recipient receipt, never from independently keyed totals.
        if (
            array_key_exists('items', $data)
            && (
                ($data['status'] ?? $existing?->status) === DispatchPlan::STATUS_RECEIVED
                || (bool) ($data['update_local_receipt'] ?? false)
                || ! empty($data['receipt_vehicle_indexes'] ?? [])
            )
        ) {
            $variances = collect($data['items'])->map(function (array $item): array {
                $loaded = (int) ($item['loaded_quantity'] ?? 0);
                $expected = (int) ($item['allocated_quantity'] ?? 0);
                $received = (int) ($item['received_quantity'] ?? 0);

                return [
                    'item_name' => $item['item_name'] ?? 'Item',
                    'quantity' => max(0, $expected - $received),
                    'disposition' => $item['variance_disposition'] ?? null,
                    'reason' => trim((string) ($item['variance_resolution'] ?? $item['return_reason'] ?? '')),
                ];
            })->filter(fn (array $row): bool => $row['quantity'] > 0)->values();

            $closedVariances = $variances->whereIn('disposition', ['returned', 'cancelled', 'lost_damaged', 'other']);
            $data['has_returned_items'] = $closedVariances->isNotEmpty();
            $data['returned_quantity'] = $closedVariances->sum('quantity');
            $data['returned_particulars'] = $closedVariances->pluck('item_name')->implode(', ') ?: null;
            $data['returned_reason'] = $closedVariances
                ->map(fn (array $row): string => $row['item_name'].': '.$row['reason'])
                ->implode("\n") ?: null;
        }

        // Form key dispatch_officer maps to DB column dispatcher; value is overwritten from auth user on save.
        if (array_key_exists('dispatch_officer', $data) && ! array_key_exists('dispatcher', $data)) {
            $data['dispatcher'] = $data['dispatch_officer'];
        }
        unset($data['dispatch_officer']);

        $data = $this->normalizeVehiclePayload($data);
        $data = $this->applySourceWarehouseDefaults($data);
        $data = $this->pruneLocalOnlyTransportVehicles($data);
        $this->assertNoReceivingLguVehicles($data);
        $this->assertVehicleScheduleDatetimes($data, $existing);
        $data = $this->aggregateLoadedQuantitiesOntoItems($data);
        $data = $this->prefillWarehousePickupDestination($data);

        return $data;
    }

    /**
     * Reject past estimated schedule times (minute precision).
     * Reject actual departed/arrival only when the calendar date is before today (Asia/Manila),
     * unless unchanged from a saved value. Also enforce arrival > departure when both are set.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertVehicleScheduleDatetimes(array $data, ?DispatchPlan $existing = null): void
    {
        $errors = [];
        $now = now()->startOfMinute();
        $today = now()->timezone(config('app.timezone', 'Asia/Manila'))->startOfDay();
        $previousRows = $existing
            ? array_values($existing->resolvedVehicleDetails())
            : [];

        foreach (array_values($data['vehicle_details'] ?? []) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $previous = is_array($previousRows[$index] ?? null) ? $previousRows[$index] : [];

            $estimatedDeparture = $this->parseScheduleDateTime($row['estimated_departure'] ?? null);
            $estimatedArrival = $this->parseScheduleDateTime($row['estimated_arrival'] ?? null);
            $previousEstimatedDeparture = $this->parseScheduleDateTime($previous['estimated_departure'] ?? null);
            $previousEstimatedArrival = $this->parseScheduleDateTime($previous['estimated_arrival'] ?? null);
            $departedAt = $this->parseScheduleDateTime($row['departed_at'] ?? null);
            $actualArrival = $this->parseScheduleDateTime($row['actual_arrival'] ?? null);

            if (
                $estimatedDeparture
                && $estimatedDeparture->lt($now)
                && (! $previousEstimatedDeparture || ! $estimatedDeparture->equalTo($previousEstimatedDeparture))
            ) {
                $errors["vehicle_details.$index.estimated_departure"] =
                    'Estimated departure cannot be earlier than the current date and time.';
            }

            if (
                $estimatedArrival
                && $estimatedArrival->lt($now)
                && (! $previousEstimatedArrival || ! $estimatedArrival->equalTo($previousEstimatedArrival))
            ) {
                $errors["vehicle_details.$index.estimated_arrival"] =
                    'Estimated arrival cannot be earlier than the current date and time.';
            } elseif (
                $estimatedDeparture
                && $estimatedArrival
                && $estimatedArrival->lt($estimatedDeparture)
            ) {
                $errors["vehicle_details.$index.estimated_arrival"] =
                    'Estimated arrival cannot be earlier than estimated departure.';
            }

            if (
                $departedAt
                && $departedAt->copy()->timezone(config('app.timezone', 'Asia/Manila'))->startOfDay()->lt($today)
            ) {
                $previousDeparted = $this->parseScheduleDateTime($previous['departed_at'] ?? null);
                if (! $previousDeparted || ! $departedAt->equalTo($previousDeparted)) {
                    $errors["vehicle_details.$index.departed_at"] =
                        'Actual departure date cannot be earlier than today.';
                }
            }

            if (
                $actualArrival
                && $actualArrival->copy()->timezone(config('app.timezone', 'Asia/Manila'))->startOfDay()->lt($today)
            ) {
                $previousArrival = $this->parseScheduleDateTime($previous['actual_arrival'] ?? null);
                $previousDeparted = $this->parseScheduleDateTime($previous['departed_at'] ?? null);
                $usesSavedHistoricalDeparture = $departedAt
                    && $previousDeparted
                    && $departedAt->equalTo($previousDeparted);
                if (
                    (! $previousArrival || ! $actualArrival->equalTo($previousArrival))
                    && ! $usesSavedHistoricalDeparture
                ) {
                    $errors["vehicle_details.$index.actual_arrival"] =
                        'Actual arrival date cannot be earlier than today.';
                }
            }

            if (
                $departedAt
                && $actualArrival
                && $actualArrival->lte($departedAt)
                && ! isset($errors["vehicle_details.$index.actual_arrival"])
            ) {
                $errors["vehicle_details.$index.actual_arrival"] =
                    'Actual arrival date and time must be after actual departure.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function parseScheduleDateTime(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse(str_replace(' ', 'T', (string) $value))->startOfMinute();
        } catch (\Throwable) {
            return null;
        }
    }

    private function dispatchOfficerName(Request $request): ?string
    {
        $name = trim((string) ($request->user()?->name ?? ''));

        return $name !== '' ? $name : null;
    }

    /** Validate only the vehicle transactions explicitly being confirmed as planned. */
    private function assertVehicleTransactionsPlannable(array $data, array $indexes): void
    {
        $errors = [];
        if (blank($data['destination'] ?? null)) $errors['destination'] = 'Delivery site / destination is required.';
        if (blank($data['receiving_agency_lgu'] ?? null)) $errors['receiving_agency_lgu'] = 'Receiving agency / organization is required.';

        foreach ($indexes as $index) {
            $index = (int) $index;
            $row = $data['vehicle_details'][$index] ?? null;
            if (! is_array($row)) {
                $row = collect($data['vehicle_details'] ?? [])
                    ->first(function ($candidate, $localIndex) use ($index): bool {
                        if (! is_array($candidate)) {
                            return false;
                        }

                        return (int) ($candidate['source_vehicle_index'] ?? $localIndex) === $index;
                    });
            }
            if (! is_array($row)) {
                $errors["vehicle_details.$index"] = 'Vehicle transaction was not found.';
                continue;
            }
            $pickup = ($row['fulfillment_type'] ?? $data['fulfillment_type'] ?? DispatchPlan::FULFILLMENT_FIELD_DELIVERY)
                === DispatchPlan::FULFILLMENT_WAREHOUSE_PICKUP;
            foreach ([
                'source_warehouse_id' => 'Source warehouse',
                'mode_of_transportation' => 'Mode of transportation',
                'land_transportation_source' => 'Transportation source',
                'vehicle_type' => 'Vehicle type',
            ] as $field => $label) {
                if (blank($row[$field] ?? null) && ($field !== 'source_warehouse_id' || blank($row['source_warehouse_name'] ?? null))) {
                    $errors["vehicle_details.$index.$field"] = "$label is required for this vehicle plan.";
                }
            }
            $softCrew = $pickup && $this->vehicleAllowsSoftCrew($row);
            if (! $softCrew) {
                foreach (['driver' => 'Driver', 'driver_contact_number' => 'Driver contact number', 'vehicle_plate_number' => 'Plate number'] as $field => $label) {
                    if (blank($row[$field] ?? null)) $errors["vehicle_details.$index.$field"] = "$label is required for this vehicle plan.";
                }
            }
            if (! $pickup) {
                if (blank($row['estimated_departure'] ?? null)) $errors["vehicle_details.$index.estimated_departure"] = 'Estimated departure is required for this vehicle plan.';
                if (blank($row['estimated_arrival'] ?? null)) $errors["vehicle_details.$index.estimated_arrival"] = 'Estimated arrival is required for this vehicle plan.';
            }
            $planned = collect($row['loaded_items'] ?? [])->sum(fn ($line) => (int) (($line['planned_quantity'] ?? $line['loaded_quantity'] ?? 0)));
            if ($planned <= 0) $errors["vehicle_details.$index.loaded_items"] = 'Assign at least one item quantity to this vehicle plan.';
        }

        // A vehicle may be planned before later batches are assigned. Enforce the
        // allocation ceiling now; full allocation coverage is a dispatch summary,
        // not a blocker for this individual vehicle transaction.
        $this->assertLoadedQuantitiesWithinAllocation($data, $errors, true, true);
        if ($errors !== []) throw ValidationException::withMessages($errors);
    }

    /** Validate only the warehouse releases selected by the user. */
    private function assertVehicleTransactionsReleasable(array $data, array $indexes): void
    {
        $errors = [];
        if (blank($data['source_of_goods'] ?? null)) $errors['source_of_goods'] = 'Select the Source of Goods before confirming release.';
        if (blank($data['purpose'] ?? null)) $errors['purpose'] = 'Select the Purpose before confirming release.';
        foreach ($indexes as $index) {
            $row = $data['vehicle_details'][$index] ?? [];
            $legacyAlreadyPlanned = in_array($data['status'] ?? null, [
                DispatchPlan::STATUS_PLANNED,
                DispatchPlan::STATUS_RELEASED,
                DispatchPlan::STATUS_IN_TRANSIT,
                DispatchPlan::STATUS_RECEIVED,
            ], true);
            if (blank($row['plan_confirmed_at'] ?? null) && ! $legacyAlreadyPlanned) {
                $errors["vehicle_details.$index.plan_confirmed_at"] = 'Confirm this vehicle plan before warehouse release.';
            }
            foreach ([
                'warehouse_released_at' => 'Warehouse release date and time',
                'release_witnessed_by' => 'Released/Witnessed by',
                'release_witness_contact_number' => 'Witness contact number',
                'release_witness_position' => 'Witness position',
                'release_witness_office' => 'Witness office',
            ] as $field => $label) {
                if (blank($row[$field] ?? null)) $errors["vehicle_details.$index.$field"] = "$label is required for this vehicle release.";
            }
            $loaded = collect($row['loaded_items'] ?? [])->sum(fn ($line) => (int) ($line['loaded_quantity'] ?? 0));
            if ($loaded <= 0) $errors["vehicle_details.$index.loaded_items"] = 'Enter the quantities actually loaded on this vehicle.';
            if (! DispatchPlan::vehicleAllowsMultiDayRun($row)
                && filled($row['estimated_departure'] ?? null)
                && filled($row['estimated_arrival'] ?? null)
                && ! DispatchPlan::isSameManilaCalendarDate($row['estimated_departure'], $row['estimated_arrival'])) {
                $errors["vehicle_details.$index.estimated_arrival"] = 'Confirm Release requires a same-day estimated schedule unless Multi-day run is checked.';
            }
        }
        $this->assertLoadedQuantitiesWithinAllocation($data, $errors);
        if ($errors !== []) throw ValidationException::withMessages($errors);
    }

    /** Dispatch status is a read-only roll-up of vehicle and direct-release transactions. */
    private function deriveDispatchStatusFromTransactions(array $data): string
    {
        $vehicles = collect($data['vehicle_details'] ?? [])->filter(fn ($row) => is_array($row)
            && (filled($row['source_warehouse_id'] ?? null) || filled($row['source_warehouse_name'] ?? null)))->values();
        $local = is_array($data['local_handover_details'] ?? null) ? $data['local_handover_details'] : [];
        $hasLocal = $this->hasLocalSourceAllocation($data);
        $hasRemote = $this->classifiedSourceWarehouses($data)
            ->contains(fn (array $warehouse): bool => (bool) ($warehouse['requires_transport'] ?? true));
        $transactionCount = $vehicles->count() + ($hasLocal ? 1 : 0);
        if ($transactionCount === 0) return DispatchPlan::STATUS_DRAFT;

        $vehicleComplete = fn (array $row): bool => filled($row['received_at'] ?? null) && (bool) ($row['receipt_acknowledged'] ?? false);
        // A completed no-transport handover must not make the whole dispatch
        // Received while a separate transport allocation still has no vehicle
        // transaction. Without this guard, the received roll-up invokes the
        // dispatch-wide validator and incorrectly asks the local transaction
        // for vehicle and loaded-quantity data.
        $allComplete = (! $hasRemote || $vehicles->isNotEmpty())
            && $vehicles->every($vehicleComplete)
            && (! $hasLocal || (filled($local['received_at'] ?? null) && (bool) ($local['receipt_acknowledged'] ?? false)));
        if ($allComplete) return DispatchPlan::STATUS_RECEIVED;

        if ($vehicles->contains(fn (array $row): bool => filled($row['departed_at'] ?? null))) return DispatchPlan::STATUS_IN_TRANSIT;
        if ($vehicles->contains(fn (array $row): bool => filled($row['warehouse_released_at'] ?? null))
            || ($hasLocal && filled($local['released_at'] ?? null))) return DispatchPlan::STATUS_RELEASED;

        $legacyDispatchPlanned = in_array($data['status'] ?? null, [
            DispatchPlan::STATUS_PLANNED,
            DispatchPlan::STATUS_RELEASED,
            DispatchPlan::STATUS_IN_TRANSIT,
            DispatchPlan::STATUS_RECEIVED,
        ], true);
        // No-transport and vehicle plans are independent transactions. As soon
        // as any one of them is confirmed, the dispatch has active operational
        // work and belongs in the In Progress queue. The remaining modules can
        // still be planned separately inside that dispatch.
        $anyPlanned = $legacyDispatchPlanned
            || $vehicles->contains(fn (array $row): bool => filled($row['plan_confirmed_at'] ?? null))
            || ($hasLocal && filled($local['plan_confirmed_at'] ?? null));

        return $anyPlanned ? DispatchPlan::STATUS_PLANNED : DispatchPlan::STATUS_DRAFT;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  string|null  $fromStatus  Prior status when updating; trust completed stages (validate only newly required ones).
     */
    private function assertStatusRequirements(
        array $data,
        string $status,
        ?string $fromStatus = null,
        ?array $releaseVehicleIndexes = null,
    ): void {
        $errors = [];
        $classifiedWarehouses = $this->classifiedSourceWarehouses($data);
        $remoteWarehouses = $classifiedWarehouses
            ->filter(fn (array $warehouse) => ($warehouse['requires_transport'] ?? true))
            ->values();
        $localWarehouses = $classifiedWarehouses
            ->filter(fn (array $warehouse) => ! ($warehouse['requires_transport'] ?? true))
            ->values();
        $hasLocalWarehouse = $localWarehouses->isNotEmpty();
        $localOnly = $classifiedWarehouses->isNotEmpty() && $remoteWarehouses->isEmpty();
        $vehicleRows = collect($data['vehicle_details'] ?? [])
            ->filter(fn ($row) => is_array($row))
            ->values();
        $vehicleCount = (int) ($data['number_of_vehicles'] ?? $vehicleRows->count());
        if ($vehicleCount <= 0) {
            $vehicleCount = $vehicleRows->count();
        }
        $isWarehousePickup = ($data['fulfillment_type'] ?? DispatchPlan::FULFILLMENT_FIELD_DELIVERY)
            === DispatchPlan::FULFILLMENT_WAREHOUSE_PICKUP;

        if ($status !== DispatchPlan::STATUS_DRAFT && ! ($data['fulfillment_type_confirmed'] ?? false)) {
            $errors['fulfillment_type'] = 'Select and confirm a delivery mode before proceeding to Planning.';
        }

        $timeline = [
            DispatchPlan::STATUS_DRAFT,
            DispatchPlan::STATUS_PLANNED,
            DispatchPlan::STATUS_RELEASED,
            DispatchPlan::STATUS_IN_TRANSIT,
            DispatchPlan::STATUS_RECEIVED,
        ];
        $statusIdx = array_search($status, $timeline, true);
        $fromIdx = $fromStatus !== null ? array_search($fromStatus, $timeline, true) : false;
        if ($statusIdx === false) {
            $statusIdx = 0;
        }
        if ($fromIdx === false) {
            $fromIdx = -1;
        }
        // Require a stage's fields only when the target reaches that stage and the prior status had not yet.
        $needsStage = function (string $completeStatus) use ($statusIdx, $fromIdx, $timeline): bool {
            $completeIdx = array_search($completeStatus, $timeline, true);
            if ($completeIdx === false) {
                return false;
            }
            if ($statusIdx < $completeIdx) {
                return false;
            }

            return $fromIdx < $completeIdx;
        };
        // Planning data remains mandatory for every vehicle throughout the later workflow.
        // Revalidate it on every non-draft save so an incomplete row cannot pass by relying
        // on a previously completed stage.
        $needsPlanning = $statusIdx >= array_search(DispatchPlan::STATUS_PLANNED, $timeline, true);
        $scopedRelease = is_array($releaseVehicleIndexes) && $releaseVehicleIndexes !== [];
        $needsRelease = $needsStage(DispatchPlan::STATUS_RELEASED)
            || ($scopedRelease && $fromStatus === DispatchPlan::STATUS_PLANNED);
        $needsTransit = $needsStage(DispatchPlan::STATUS_IN_TRANSIT);
        $needsReceipt = $needsStage(DispatchPlan::STATUS_RECEIVED);
        $localHandover = is_array($data['local_handover_details'] ?? null) ? $data['local_handover_details'] : [];
        $scopedReleaseIndexSet = $scopedRelease
            ? array_fill_keys(array_map('intval', $releaseVehicleIndexes), true)
            : null;

        if ($isWarehousePickup && $status === DispatchPlan::STATUS_IN_TRANSIT) {
            $errors['status'] = 'In Transit is not used for Partner/Recipient Pickup. After release, record the pickup receipt and custody acknowledgment.';
        }

        if ($localOnly && $status === DispatchPlan::STATUS_IN_TRANSIT) {
            $errors['status'] = 'In Transit is not used when all allocations are already at the recipient custody location. After release, confirm recipient receipt.';
        }

        if ($needsPlanning) {
            foreach (collect($data['items'] ?? [])->values() as $index => $item) {
                $item = is_array($item) ? $item : [];
                if ((int) ($item['allocated_quantity'] ?? 0) <= 0) {
                    continue;
                }
                if (blank($item['warehouse_id'] ?? null) && blank($item['warehouse_name'] ?? null)) {
                    $label = filled($item['item_name'] ?? null) ? $item['item_name'] : 'This allocated item';
                    $errors["items.$index.warehouse_id"] = "$label has no source warehouse. Return to the RIS allocation and select its source warehouse.";
                }
            }
            if (blank($data['destination'] ?? null)) {
                $errors['destination'] = $isWarehousePickup
                    ? 'Warehouse / pickup point destination is required for this status.'
                    : 'Delivery site / destination is required for this status.';
            }
            if (blank($data['receiving_agency_lgu'] ?? null)) {
                $errors['receiving_agency_lgu'] = 'Receiving agency / organization is required for this status.';
            }
            if ($hasLocalWarehouse && blank($localHandover['expected_release_at'] ?? null)) {
                $errors['local_handover_details.expected_release_at'] = 'Estimated / expected release date is required for the local warehouse release.';
            }

            // Receiving-LGU-only plans do not need DSWD vehicle delivery planning.
            if ($remoteWarehouses->isNotEmpty()) {
                if ($vehicleRows->isEmpty()) {
                    $errors['vehicle_details'] = 'Enter vehicle details for warehouses that require DSWD transport.';
                    $errors['number_of_vehicles'] = 'No. of Vehicles is required for remote warehouse allocations.';
                } elseif ($vehicleCount > 0 && $vehicleRows->count() !== $vehicleCount) {
                    $errors['number_of_vehicles'] = 'No. of Vehicles must match the number of vehicle rows entered.';
                    $errors['vehicle_details'] = "Enter details for all {$vehicleCount} vehicle(s).";
                }

                foreach ($remoteWarehouses as $warehouse) {
                    $covered = $vehicleRows->contains(fn (array $row) =>
                        $this->vehicleMatchesSourceWarehouse($row, collect([$warehouse])));
                    if (! $covered) {
                        $label = $warehouse['name'] !== '' ? $warehouse['name'] : 'a remote warehouse';
                        $errors['vehicle_details'] = ($errors['vehicle_details'] ?? null)
                            ?: "Add at least one vehicle for {$label} (DSWD transport required).";
                    }
                }

                $sourceWarehouses = $classifiedWarehouses
                    ->filter(fn (array $warehouse) => ($warehouse['requires_transport'] ?? true))
                    ->values();

                foreach ($vehicleRows as $index => $row) {
                    $row = is_array($row) ? $row : [];
                    $rowIsPickup = ($row['fulfillment_type'] ?? $data['fulfillment_type'] ?? DispatchPlan::FULFILLMENT_FIELD_DELIVERY)
                        === DispatchPlan::FULFILLMENT_WAREHOUSE_PICKUP;
                    if ($this->vehicleSourceRequiresNoTransport($row, $data, $classifiedWarehouses)) {
                        $errors["vehicle_details.$index.source_warehouse_id"] =
                            'Vehicle delivery planning is not allowed for a source already at the recipient custody location. Assign a remote source warehouse or remove this vehicle.';

                        continue;
                    }
                    if ($sourceWarehouses->isNotEmpty()) {
                        $hasWarehouse = $this->vehicleSourceWarehouses($row) !== [];
                        if (! $hasWarehouse) {
                            $errors["vehicle_details.$index.source_warehouse_id"] =
                                'Source warehouse is required for each vehicle.';
                        } elseif (! $this->vehicleMatchesSourceWarehouse($row, $sourceWarehouses)) {
                            $errors["vehicle_details.$index.source_warehouse_id"] =
                                'Source warehouse must match an allocated remote warehouse on this plan.';
                        }
                    }
                    if (! $rowIsPickup) {
                        if (blank($row['estimated_departure'] ?? null)) {
                            $errors["vehicle_details.$index.estimated_departure"] = 'Estimated departure date/time is required for each vehicle.';
                        }
                        if (blank($row['estimated_arrival'] ?? null)) {
                            $errors["vehicle_details.$index.estimated_arrival"] = 'Estimated arrival date/time is required for each vehicle.';
                        }
                    }
                    $mode = is_array($row['mode_of_transportation'] ?? null)
                        ? ($this->asStringList($row['mode_of_transportation'])[0] ?? null)
                        : ($row['mode_of_transportation'] ?? null);
                    if (blank($mode)) {
                        $errors["vehicle_details.$index.mode_of_transportation"] = 'Mode of transportation is required for each vehicle.';
                    }
                    if (blank($row['land_transportation_source'] ?? null)) {
                        $errors["vehicle_details.$index.land_transportation_source"] = 'Land transportation source is required for each vehicle.';
                    }
                    if (blank($row['vehicle_type'] ?? null)) {
                        $errors["vehicle_details.$index.vehicle_type"] = 'Vehicle type is required for each vehicle.';
                    }
                    $softCrew = $rowIsPickup && $this->vehicleAllowsSoftCrew($row);
                    if (! $softCrew && blank($row['driver'] ?? null)) {
                        $errors["vehicle_details.$index.driver"] = 'Driver is required for each vehicle.';
                    }
                    if (! $softCrew && blank($row['vehicle_plate_number'] ?? null)) {
                        $errors["vehicle_details.$index.vehicle_plate_number"] = 'Plate number is required for each vehicle.';
                    }
                    $modeLabel = filled($mode) ? (string) $mode : '';
                    if (! $softCrew && blank($row['driver_contact_number'] ?? null)) {
                        $errors["vehicle_details.$index.driver_contact_number"] = 'Driver contact number is required for each vehicle.';
                    }
                    if (! $softCrew && $modeLabel === 'DSWD-Owned') {
                        foreach ([
                            'driver_position' => 'Driver position',
                            'driver_office' => 'Driver office',
                        ] as $field => $label) {
                            if (blank($row[$field] ?? null)) {
                                $errors["vehicle_details.$index.$field"] = "$label is required for DSWD-Owned vehicles.";
                            }
                        }
                    }
                    if ($this->vehicleHasDswdEscort($row)) {
                        foreach ([
                            'escort_name' => 'Escort name',
                            'escort_contact_number' => 'Escort contact number',
                            'escort_position' => 'Escort position',
                            'escort_office' => 'Escort office',
                        ] as $field => $label) {
                            if (blank($row[$field] ?? null)) {
                                $errors["vehicle_details.$index.$field"] = "$label is required when a DSWD escort is assigned.";
                            }
                        }
                    }
                }

                $this->assertLoadedQuantitiesWithinAllocation($data, $errors, true);
            }
        }

        // Same-day release gate (Asia/Manila): hard-block Confirm Release for transport vehicles
        // unless Multi-day run is checked. Receiving-LGU-only / no-vehicle plans are skipped above.
        if (
            $needsRelease
            && ! $isWarehousePickup
            && $remoteWarehouses->isNotEmpty()
        ) {
            foreach ($vehicleRows as $index => $row) {
                $row = is_array($row) ? $row : [];
                if ($scopedReleaseIndexSet !== null && ! isset($scopedReleaseIndexSet[(int) $index])) {
                    continue;
                }
                if ($this->vehicleSourceRequiresNoTransport($row, $data, $classifiedWarehouses)) {
                    continue;
                }
                if (DispatchPlan::vehicleAllowsMultiDayRun($row)) {
                    continue;
                }
                $departure = $row['estimated_departure'] ?? null;
                $arrival = $row['estimated_arrival'] ?? null;
                if (blank($departure) || blank($arrival)) {
                    continue;
                }
                if (! DispatchPlan::isSameManilaCalendarDate($departure, $arrival)) {
                    $errors["vehicle_details.$index.estimated_arrival"] =
                        'Confirm Release requires estimated departure and estimated arrival on the same calendar date (Asia/Manila). '
                        .'Revise Planning dates, save/update the plan, then Confirm Release. '
                        .'Check Multi-day run only when a multi-day trip is justified.';
                }
            }
        }

        if ($needsRelease) {
            if (blank($data['source_of_goods'] ?? null)) {
                $errors['source_of_goods'] = 'Select the Source of Goods before confirming release.';
            }
            if (blank($data['purpose'] ?? null)) {
                $errors['purpose'] = 'Select the Purpose before confirming release.';
            }
            // Local handover release is its own custody event — skip when confirming a remote warehouse only.
            if ($hasLocalWarehouse && $scopedReleaseIndexSet === null) {
                foreach ([
                    'release_witness_affiliation' => 'Released/witnessed by organization',
                    'released_by' => 'Personnel who released the items',
                    'releaser_position' => 'Releasing personnel position',
                    'releaser_office' => 'Releasing personnel office',
                    'releaser_contact' => 'Releasing personnel contact number',
                    'released_at' => 'Actual release date and time',
                ] as $field => $label) {
                    if (blank($localHandover[$field] ?? null)) $errors["local_handover_details.$field"] = "$label is required.";
                }
            }
        }

        if ($needsRelease && $remoteWarehouses->isNotEmpty()) {
            foreach ($vehicleRows as $index => $row) {
                $row = is_array($row) ? $row : [];
                if ($scopedReleaseIndexSet !== null && ! isset($scopedReleaseIndexSet[(int) $index])) {
                    continue;
                }
                if ($this->vehicleSourceRequiresNoTransport($row, $data, $classifiedWarehouses)) {
                    continue;
                }
                if (blank($row['warehouse_released_at'] ?? null)) {
                    $errors["vehicle_details.$index.warehouse_released_at"] = 'Warehouse release date/time is required for each vehicle.';
                }
                $mode = is_array($row['mode_of_transportation'] ?? null)
                    ? ($this->asStringList($row['mode_of_transportation'])[0] ?? null)
                    : ($row['mode_of_transportation'] ?? null);
                if (
                    in_array($mode, ['Partner', 'Partner LGU'], true)
                    && ! $this->vehicleHasDswdEscort($row)
                    && ! in_array($row['release_witness_affiliation'] ?? null, ['dswd', 'lgu'], true)
                ) {
                    $errors["vehicle_details.$index.release_witness_affiliation"] =
                        'Select whether the person who released/witnessed the goods is from DSWD or the partner/recipient organization.';
                }
                if (blank($row['release_witnessed_by'] ?? null)) {
                    $errors["vehicle_details.$index.release_witnessed_by"] = 'Released/Witnessed by is required for each vehicle.';
                }
                if (blank($row['release_witness_contact_number'] ?? null)) {
                    $errors["vehicle_details.$index.release_witness_contact_number"] = 'Witness contact number is required for each vehicle.';
                }
                if (blank($row['release_witness_position'] ?? null)) {
                    $errors["vehicle_details.$index.release_witness_position"] = 'Witness position is required for each vehicle.';
                }
                if (blank($row['release_witness_office'] ?? null)) {
                    $errors["vehicle_details.$index.release_witness_office"] = 'Witness office is required for each vehicle.';
                }

                $loadedTotal = collect($row['loaded_items'] ?? [])
                    ->sum(fn ($item) => (int) (is_array($item) ? ($item['loaded_quantity'] ?? 0) : 0));
                if ($loadedTotal <= 0) {
                    $errors["vehicle_details.$index.loaded_items"] = 'Enter loaded quantities for each vehicle being released.';
                }
            }

            if ($scopedReleaseIndexSet === null) {
                $this->assertLoadedQuantitiesWithinAllocation($data, $errors);
            }
        }

        // Field delivery requires departed_at when newly entering in_transit (or jumping to received).
        // Local-only and warehouse pickup skip transit departure checks.
        if (
            $needsTransit
            && $remoteWarehouses->isNotEmpty()
        ) {
            foreach ($vehicleRows as $index => $row) {
                $row = is_array($row) ? $row : [];
                $rowIsPickup = ($row['fulfillment_type'] ?? $data['fulfillment_type'] ?? DispatchPlan::FULFILLMENT_FIELD_DELIVERY)
                    === DispatchPlan::FULFILLMENT_WAREHOUSE_PICKUP;
                if ($rowIsPickup) {
                    continue;
                }
                if ($this->vehicleSourceRequiresNoTransport($row, $data, $classifiedWarehouses)) {
                    continue;
                }
                if (blank($row['departed_at'] ?? null)) {
                    $errors["vehicle_details.$index.departed_at"] = 'Departure date/time is required for each vehicle.';
                }
            }
        }

        if ($needsReceipt) {
            if ($hasLocalWarehouse) {
                foreach ([
                    'received_by' => 'Actual receiving representative',
                    'receiver_position' => 'Receiving representative position',
                    'receiver_office' => 'Receiving representative office',
                    'receiver_contact' => 'Receiving representative contact number',
                    'received_at' => 'Receipt date and time',
                ] as $field => $label) {
                    if (blank($localHandover[$field] ?? null)) $errors["local_handover_details.$field"] = "$label is required.";
                }
                if (! ($localHandover['receipt_acknowledged'] ?? false)) $errors['local_handover_details.receipt_acknowledged'] = 'The recipient must acknowledge receipt from the local warehouse.';
                if (
                    filled($localHandover['released_at'] ?? null)
                    && filled($localHandover['received_at'] ?? null)
                    && Carbon::parse($localHandover['received_at'])->lt(Carbon::parse($localHandover['released_at']))
                ) {
                    $errors['local_handover_details.received_at'] = 'Receipt date and time cannot be earlier than the actual release date and time.';
                }
            }
            if ($remoteWarehouses->isNotEmpty()) {
                foreach ($vehicleRows as $index => $row) {
                    $row = is_array($row) ? $row : [];
                    $rowIsPickup = ($row['fulfillment_type'] ?? $data['fulfillment_type'] ?? DispatchPlan::FULFILLMENT_FIELD_DELIVERY)
                        === DispatchPlan::FULFILLMENT_WAREHOUSE_PICKUP;
                    if ($this->vehicleSourceRequiresNoTransport($row, $data, $classifiedWarehouses)) {
                        continue;
                    }
                    if (blank($row['received_by'] ?? null)) {
                        $errors["vehicle_details.$index.received_by"] = 'Actual Receiving Representative is required for each vehicle.';
                    }
                    if (blank($row['received_by_position'] ?? null)) {
                        $errors["vehicle_details.$index.received_by_position"] = 'Recipient position is required for each vehicle.';
                    }
                    if (blank($row['received_by_office'] ?? null)) {
                        $errors["vehicle_details.$index.received_by_office"] = 'Recipient office is required for each vehicle.';
                    }
                    if (blank($row['received_at'] ?? null)) {
                        $errors["vehicle_details.$index.received_at"] = 'Recipient receipt date/time is required for each vehicle.';
                    }
                    if (blank($row['receiver_contact'] ?? null)) {
                        $errors["vehicle_details.$index.receiver_contact"] = 'Recipient contact number is required for each vehicle.';
                    }
                    if (! ($row['receipt_acknowledged'] ?? false)) {
                        $errors["vehicle_details.$index.receipt_acknowledged"] = 'Acknowledge recipient receipt for each vehicle.';
                    }
                    if ($rowIsPickup && $this->normalizeYesNo($row['fully_delivered'] ?? null) === null) {
                        $errors["vehicle_details.$index.fully_delivered"] = 'Fully picked-up must be Yes or No for each pickup transaction.';
                    }
                }
            }

            $hasUndeliveredItem = false;
            foreach (array_values($data['items'] ?? []) as $index => $item) {
                $loaded = (int) ($item['loaded_quantity'] ?? 0);
                $expected = (int) ($item['allocated_quantity'] ?? 0);
                if (! array_key_exists('received_quantity', $item) || $item['received_quantity'] === null || $item['received_quantity'] === '') {
                    $errors["items.$index.received_quantity"] = 'Received quantity is required for each item.';

                    continue;
                }
                $received = (int) $item['received_quantity'];
                $hasUndeliveredItem = $hasUndeliveredItem || $expected > $received;
                if ($received > $expected) {
                    $errors["items.$index.received_quantity"] = 'Received quantity cannot exceed the loaded or allocated quantity.';
                }
                if ($expected > $received && blank($item['variance_disposition'] ?? null)) {
                    $errors["items.$index.variance_disposition"] = 'Select what will happen to the undelivered balance.';
                }
                if ($expected > $received && blank($item['variance_resolution'] ?? $item['return_reason'] ?? null)) {
                    $errors["items.$index.variance_resolution"] = 'Explain the reason or resolution for this delivery variance.';
                }
                if (($item['variance_disposition'] ?? null) === 'returned') {
                    foreach (['return_condition', 'return_stock_disposition', 'return_received_at', 'return_inspected_by'] as $field) {
                        if (blank($item[$field] ?? null)) {
                            $errors["items.$index.$field"] = match ($field) {
                                'return_condition' => 'Record the condition found during warehouse inspection.',
                                'return_stock_disposition' => 'Select the warehouse stock disposition for the returned quantity.',
                                'return_received_at' => 'Record when the warehouse physically received the returned items.',
                                default => 'Record the warehouse personnel who inspected the returned items.',
                            };
                        }
                    }
                    $condition = $item['return_condition'] ?? null;
                    $disposition = $item['return_stock_disposition'] ?? null;
                    if ($condition === 'serviceable' && $disposition !== 'restock_available') {
                        $errors["items.$index.return_stock_disposition"] = 'Serviceable returns must be restored to available stock.';
                    }
                    if ($condition === 'near_expiry' && $disposition !== 'restricted_priority') {
                        $errors["items.$index.return_stock_disposition"] = 'Near-expiry returns must enter restricted priority-disposition stock.';
                    }
                    if (in_array($condition, ['damaged', 'expired'], true) && $disposition !== 'quarantine_disposal') {
                        $errors["items.$index.return_stock_disposition"] = 'Damaged or expired returns must be quarantined for disposal and cannot return to available stock.';
                    }
                }
            }
            if (
                $this->normalizeYesNo($data['has_returned_items'] ?? null) === true
                && ! $hasUndeliveredItem
            ) {
                $errors['has_returned_items'] = 'Enter at least one returned or cancelled quantity, or select No.';
            }
            if (
                $this->normalizeYesNo($data['has_returned_items'] ?? null) === false
                && $hasUndeliveredItem
            ) {
                $errors['has_returned_items'] = 'Select Yes to record the undelivered balance and its disposition.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Confirm Release must use planning details that were saved while the plan was Planned.
     * This prevents a user from correcting a stale/multi-day estimate and releasing stock in
     * the same action, which would make the planning revision invisible as a separate audit event.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertReleaseFollowsSavedPlan(
        DispatchPlan $dispatch,
        array $data,
        string $status,
        string $previousStatus,
    ): void {
        if ($status !== DispatchPlan::STATUS_RELEASED || $previousStatus === DispatchPlan::STATUS_RELEASED) {
            return;
        }

        if ($previousStatus !== DispatchPlan::STATUS_PLANNED) {
            throw ValidationException::withMessages([
                'status' => 'Mark the dispatch plan as Planned before confirming release.',
            ]);
        }

        $savedRows = array_values($dispatch->resolvedVehicleDetails());
        $submittedRows = array_values($data['vehicle_details'] ?? []);

        foreach ($submittedRows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $saved = is_array($savedRows[$index] ?? null) ? $savedRows[$index] : [];
            $savedDeparture = $saved['estimated_departure'] ?? null;
            $savedArrival = $saved['estimated_arrival'] ?? null;
            $savedWasReady = DispatchPlan::vehicleAllowsMultiDayRun($saved)
                || DispatchPlan::isSameManilaCalendarDate($savedDeparture, $savedArrival);

            $planningChanged = ($row['estimated_departure'] ?? null) !== $savedDeparture
                || ($row['estimated_arrival'] ?? null) !== $savedArrival
                || DispatchPlan::vehicleAllowsMultiDayRun($row) !== DispatchPlan::vehicleAllowsMultiDayRun($saved);

            if (! $savedWasReady && $planningChanged) {
                throw ValidationException::withMessages([
                    "vehicle_details.$index.estimated_arrival" => 'Save the revised Planning dates (or Multi-day run exception) first. '
                        .'After the plan update is recorded in history, confirm release.',
                ]);
            }
        }
    }

    /**
     * Partner LGU / N/A vehicle types may omit driver & plate for warehouse pickup.
     *
     * @param  array<string, mixed>  $row
     */
    private function vehicleAllowsSoftCrew(array $row): bool
    {
        $mode = is_array($row['mode_of_transportation'] ?? null)
            ? ($this->asStringList($row['mode_of_transportation'])[0] ?? null)
            : ($row['mode_of_transportation'] ?? null);
        $mode = $mode === 'Partner LGU' ? 'Partner' : $mode;
        $type = strtolower(trim((string) ($row['vehicle_type'] ?? '')));

        return $mode === 'Partner'
            || $type === ''
            || in_array($type, ['n/a', 'na', 'n.a.', 'none', 'not applicable'], true);
    }

    /**
     * Best-effort destination prefill when switching to warehouse pickup with an empty site.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prefillWarehousePickupDestination(array $data): array
    {
        if (($data['fulfillment_type'] ?? null) !== DispatchPlan::FULFILLMENT_WAREHOUSE_PICKUP) {
            return $data;
        }
        if (filled($data['destination'] ?? null)) {
            return $data;
        }

        $warehouse = collect($data['items'] ?? [])
            ->map(fn ($item) => is_array($item) ? trim((string) ($item['warehouse_name'] ?? '')) : '')
            ->first(fn (string $name) => $name !== '');

        $data['destination'] = $warehouse !== null && $warehouse !== ''
            ? "{$warehouse} (Warehouse pickup)"
            : 'Warehouse pickup';

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $errors
     */
    private function assertLoadedQuantitiesWithinAllocation(array $data, array &$errors, bool $requireExact = false, bool $allowUnderAllocation = false): void
    {
        $items = collect($data['items'] ?? []);
        if ($items->isEmpty()) {
            return;
        }

        $totals = [];
        foreach ($data['vehicle_details'] ?? [] as $vehicleIndex => $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach ($row['loaded_items'] ?? [] as $lineIndex => $line) {
                if (! is_array($line)) {
                    continue;
                }
                $key = $this->itemMatchKey($line);
                if ($key === '') {
                    continue;
                }
                if (! filled($line['requisition_issuance_item_id'] ?? null)) {
                    $namedItem = $items->first(fn ($item): bool => is_array($item)
                        && strcasecmp(trim((string) ($item['item_name'] ?? '')), trim((string) ($line['item_name'] ?? ''))) === 0);
                    if (is_array($namedItem)) {
                        $key = $this->itemMatchKey($namedItem);
                    }
                }
                $quantityField = $requireExact ? 'planned_quantity' : 'loaded_quantity';
                $qty = (int) ($line[$quantityField] ?? $line['loaded_quantity'] ?? 0);
                $totals[$key] = ($totals[$key] ?? 0) + $qty;
                if ($qty < 0) {
                    $errors["vehicle_details.$vehicleIndex.loaded_items.$lineIndex.loaded_quantity"] = 'Loaded quantity cannot be negative.';
                }
                if ($qty > 0 && (filled($row['source_warehouse_id'] ?? null) || filled($row['source_warehouse_name'] ?? null))) {
                    $matchedItem = $items->first(function ($item) use ($key) {
                        return is_array($item) && $this->itemMatchKey($item) === $key;
                    });
                    if (is_array($matchedItem) && ! $this->itemMatchesVehicleWarehouse($matchedItem, $row)) {
                        $errors["vehicle_details.$vehicleIndex.loaded_items.$lineIndex.loaded_quantity"] =
                            'Loaded quantity must belong to this vehicle\'s source warehouse.';
                    }
                }
            }
        }

        $classifiedWarehouses = $this->classifiedSourceWarehouses($data);
        foreach ($items as $itemIndex => $item) {
            if (! is_array($item)) {
                continue;
            }
            $sourceWarehouse = $classifiedWarehouses->first(fn (array $warehouse): bool => $this->warehouseKeysMatch(
                $item['warehouse_id'] ?? null,
                $item['warehouse_name'] ?? null,
                $warehouse['id'] ?? null,
                $warehouse['name'] ?? null,
            ));
            // Local recipient-held stock is released directly and never loaded
            // onto a dispatch vehicle. Validate it through local handover instead.
            if ($sourceWarehouse && ! ($sourceWarehouse['requires_transport'] ?? true)) {
                continue;
            }
            $key = $this->itemMatchKey($item);
            $allocated = (int) ($item['allocated_quantity'] ?? 0);
            $loaded = (int) ($totals[$key] ?? 0);
            if ($loaded > $allocated) {
                $errors["items.$itemIndex.loaded_quantity"] = "Total loaded quantity across vehicles ({$loaded}) exceeds allocated ({$allocated}) for {$item['item_name']}.";
            } elseif ($requireExact && ! $allowUnderAllocation && $loaded !== $allocated) {
                $errors["items.$itemIndex.loaded_quantity"] = "Total To Be Loaded across all vehicles ({$loaded}) must equal allocated ({$allocated}) for {$item['item_name']}.";
            }
        }
    }

    private function itemMatchKey(array $item): string
    {
        if (filled($item['requisition_issuance_item_id'] ?? null)) {
            return 'ris:'.(int) $item['requisition_issuance_item_id'];
        }

        $name = strtolower(trim((string) ($item['item_name'] ?? '')));

        return $name !== '' ? 'name:'.$name : '';
    }

    /**
     * Sum per-vehicle loaded quantities onto plan items for legacy print / RIS mirrors.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function aggregateLoadedQuantitiesOntoItems(array $data): array
    {
        if (! isset($data['items']) || ! is_array($data['items'])) {
            return $data;
        }

        $totals = [];
        $remarks = [];
        foreach ($data['vehicle_details'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach ($row['loaded_items'] ?? [] as $line) {
                if (! is_array($line)) {
                    continue;
                }
                $key = $this->itemMatchKey($line);
                if ($key === '') {
                    continue;
                }
                $totals[$key] = ($totals[$key] ?? 0) + (int) ($line['loaded_quantity'] ?? 0);
                if (filled($line['remarks'] ?? null) && ! isset($remarks[$key])) {
                    $remarks[$key] = trim((string) $line['remarks']);
                }
            }
        }

        $data['items'] = collect($data['items'])->map(function (array $item) use ($totals, $remarks) {
            $key = $this->itemMatchKey($item);
            if ($key !== '' && array_key_exists($key, $totals)) {
                $item['loaded_quantity'] = $totals[$key];
            }
            if ($key !== '' && isset($remarks[$key]) && blank($item['remarks'] ?? null)) {
                $item['remarks'] = $remarks[$key];
            }

            return $item;
        })->all();

        return $data;
    }

    private function seedFromRis(RequisitionIssuanceSlip $slip, array $data): array
    {
        $tracking = is_array($slip->tracking_data) ? $slip->tracking_data : [];

        $modes = $data['mode_of_transportation']
            ?? $this->asStringList($tracking['mode_of_transportation'] ?? null);
        $vehicleTypes = $data['vehicle_types']
            ?? $this->asStringList($tracking['vehicle_type'] ?? $tracking['vehicle_types'] ?? null);
        $vehicleCount = $data['number_of_vehicles']
            ?? ($tracking['number_of_vehicles'] ?? $tracking['no_of_vehicles'] ?? null);

        $legacyDeliveredAt = $slip->delivered_at?->format('Y-m-d')
            ?: ($tracking['delivered_at'] ?? null);
        $legacyWitness = $slip->release_witnessed_by ?: ($tracking['release_witnessed_by'] ?? null);
        $legacyFullyDelivered = array_key_exists('fully_delivered', $data)
            ? $this->normalizeYesNo($data['fully_delivered'])
            : $this->normalizeYesNo($slip->fully_delivered ?? ($tracking['fully_delivered'] ?? null));
        $legacyHasReturned = array_key_exists('has_returned_items', $data)
            ? $this->normalizeYesNo($data['has_returned_items'])
            : $this->normalizeYesNo($slip->has_returned_items ?? ($tracking['has_returned_items'] ?? null));

        $driver = $data['driver'] ?? ($slip->driver_name ?: ($tracking['driver_name'] ?? null));
        $driverContact = $data['driver_contact_number']
            ?? ($slip->driver_contact_number ?: ($tracking['driver_contact_number'] ?? null));
        $plate = $data['vehicle_plate_number']
            ?? ($slip->vehicle_plate_number ?: ($tracking['vehicle_plate_number'] ?? null));
        $resolvedCount = filled($vehicleCount) ? max(1, min(99, (int) $vehicleCount)) : null;

        $vehicleDetails = $data['vehicle_details'] ?? null;
        if (! is_array($vehicleDetails) || $vehicleDetails === []) {
            $seedMode = is_array($modes) ? ($modes[0] ?? null) : $modes;
            if ($seedMode === 'Partner') {
                $seedMode = 'Partner';
            }
            $seedRow = [
                'vehicle_type' => $vehicleTypes[0] ?? null,
                'driver' => $driver,
                'driver_contact_number' => $driverContact,
                'vehicle_plate_number' => $plate,
                'mode_of_transportation' => $seedMode,
                'estimated_departure' => $data['estimated_departure']
                    ?? (filled($data['dispatch_date'] ?? null) ? $data['dispatch_date'] : null),
                'estimated_arrival' => $data['estimated_arrival'] ?? null,
                'warehouse_released_at' => $data['warehouse_released_at'] ?? null,
                'warehouse_released_by' => $data['warehouse_released_by'] ?? null,
                'release_witnessed_by' => $data['release_witnessed_by'] ?? $legacyWitness,
                'loaded_at' => $data['loaded_at'] ?? null,
                'loading_remarks' => $data['loading_remarks'] ?? null,
                'departed_at' => $data['departed_at'] ?? null,
                'actual_arrival' => $data['actual_arrival'] ?? null,
                'delivered_at' => $data['delivered_at'] ?? $legacyDeliveredAt,
                'fully_delivered' => $legacyFullyDelivered === null
                    ? null
                    : ($legacyFullyDelivered ? 'Yes' : 'No'),
                'received_by' => $data['received_by'] ?? ($slip->received_by ?: ($tracking['received_by'] ?? null)),
                'received_at' => $data['received_at'] ?? null,
                'receiver_contact' => $data['receiver_contact'] ?? ($slip->contact_number ?: null),
                'receipt_acknowledged' => (bool) ($data['receipt_acknowledged'] ?? false),
                'receipt_remarks' => $data['receipt_remarks'] ?? null,
                'loaded_items' => [],
            ];
            $vehicleDetails = [$seedRow];
            $targetCount = $resolvedCount ?? 1;
            while (count($vehicleDetails) < $targetCount) {
                $index = count($vehicleDetails);
                $vehicleDetails[] = [
                    'vehicle_type' => $vehicleTypes[$index] ?? null,
                    'driver' => null,
                    'driver_contact_number' => null,
                    'vehicle_plate_number' => null,
                    'mode_of_transportation' => null,
                    'loaded_items' => [],
                ];
            }
        }

        $normalizedVehicles = $this->normalizeVehiclePayload([
            ...$data,
            'vehicle_details' => $vehicleDetails,
            'number_of_vehicles' => $resolvedCount,
            'vehicle_types' => $vehicleTypes ?: null,
            'mode_of_transportation' => $modes ?: null,
            'driver' => $driver,
            'driver_contact_number' => $driverContact,
            'vehicle_plate_number' => $plate,
        ]);

        $seeded = [
            'destination' => $data['destination'] ?? $slip->delivery_site,
            'receiving_agency_lgu' => $data['receiving_agency_lgu'] ?? $slip->recipient,
            'source_of_goods' => $data['source_of_goods'] ?? null,
            'purpose' => $data['purpose']
                ?? ($slip->purpose_of_release ?: ($slip->request?->purpose ?? null)),
            'driver' => $normalizedVehicles['driver'] ?? $driver,
            'driver_contact_number' => $normalizedVehicles['driver_contact_number'] ?? $driverContact,
            'vehicle_plate_number' => $normalizedVehicles['vehicle_plate_number'] ?? $plate,
            'dispatcher' => $data['dispatcher'] ?? null, // Forced from auth user in store/update.
            'mode_of_transportation' => $normalizedVehicles['mode_of_transportation'] ?? ($modes ?: null),
            'vehicle_types' => $normalizedVehicles['vehicle_types'] ?? ($vehicleTypes ?: null),
            'number_of_vehicles' => $normalizedVehicles['number_of_vehicles'] ?? $resolvedCount,
            'vehicle_details' => $normalizedVehicles['vehicle_details'] ?? $vehicleDetails,
            'dispatch_date' => $normalizedVehicles['dispatch_date']
                ?? ($data['dispatch_date'] ?? null),
            'estimated_arrival' => $normalizedVehicles['estimated_arrival'] ?? ($data['estimated_arrival'] ?? null),
            'actual_arrival' => $normalizedVehicles['actual_arrival'] ?? ($data['actual_arrival'] ?? null),
            'vehicle_id' => null,
            'warehouse_released_at' => $normalizedVehicles['warehouse_released_at'] ?? ($data['warehouse_released_at'] ?? null),
            'warehouse_released_by' => $normalizedVehicles['warehouse_released_by'] ?? ($data['warehouse_released_by'] ?? null),
            'loaded_at' => $normalizedVehicles['loaded_at'] ?? ($data['loaded_at'] ?? null),
            'loading_remarks' => $normalizedVehicles['loading_remarks'] ?? ($data['loading_remarks'] ?? null),
            'departed_at' => $normalizedVehicles['departed_at'] ?? ($data['departed_at'] ?? null),
            'received_by' => $normalizedVehicles['received_by']
                ?? ($data['received_by'] ?? ($slip->received_by ?: ($tracking['received_by'] ?? null))),
            'received_at' => $normalizedVehicles['received_at'] ?? ($data['received_at'] ?? null),
            'receiver_contact' => $normalizedVehicles['receiver_contact']
                ?? ($data['receiver_contact'] ?? ($slip->contact_number ?: null)),
            'receipt_acknowledged' => (bool) ($normalizedVehicles['receipt_acknowledged']
                ?? ($data['receipt_acknowledged'] ?? false)),
            'receipt_remarks' => $normalizedVehicles['receipt_remarks'] ?? ($data['receipt_remarks'] ?? null),
            'delivered_at' => $normalizedVehicles['delivered_at'] ?? ($data['delivered_at'] ?? $legacyDeliveredAt),
            'release_witnessed_by' => $normalizedVehicles['release_witnessed_by']
                ?? ($data['release_witnessed_by'] ?? $legacyWitness),
            'fully_delivered' => array_key_exists('fully_delivered', $normalizedVehicles)
                ? $this->normalizeYesNo($normalizedVehicles['fully_delivered'])
                : $legacyFullyDelivered,
            'has_returned_items' => $legacyHasReturned,
            'returned_particulars' => $data['returned_particulars']
                ?? ($slip->returned_particulars ?: ($tracking['returned_particulars'] ?? null)),
            'returned_quantity' => array_key_exists('returned_quantity', $data)
                ? $data['returned_quantity']
                : ($slip->returned_quantity ?? ($tracking['returned_quantity'] ?? null)),
            'returned_reason' => $data['returned_reason']
                ?? ($slip->returned_reason ?: ($tracking['returned_reason'] ?? null)),
            'remarks' => $data['remarks'] ?? null,
            'fulfillment_type' => $data['fulfillment_type'] ?? DispatchPlan::FULFILLMENT_FIELD_DELIVERY,
            'fulfillment_type_confirmed' => (bool) ($data['fulfillment_type_confirmed'] ?? false),
            'local_handover_details' => is_array($data['local_handover_details'] ?? null)
                ? $data['local_handover_details']
                : [],
        ];

        $planItems = $data['items'] ?? $this->itemsFromSlip($slip)->all();
        $withDestination = $this->prefillWarehousePickupDestination([
            ...$seeded,
            'items' => $planItems,
        ]);
        $seeded['destination'] = $withDestination['destination'] ?? $seeded['destination'];

        $withWarehouses = $this->applySourceWarehouseDefaults([
            ...$seeded,
            'items' => $planItems,
            'request_id' => $data['request_id'] ?? $slip->request_id,
            'receiving_agency_lgu' => $seeded['receiving_agency_lgu'] ?? null,
        ]);
        $withWarehouses = $this->pruneLocalOnlyTransportVehicles($withWarehouses);

        return Arr::except($withWarehouses, ['items']);
    }

    private function syncItems(DispatchPlan $dispatch, ?array $items, ?RequisitionIssuanceSlip $slip): void
    {
        if ($items === null && $slip) {
            $items = $this->itemsFromSlip($slip)->all();
        }

        if ($items === null) {
            return;
        }

        $existingItems = $dispatch->items()->get();
        $allocationItems = $slip?->allocationItems()->get() ?? collect();
        $dispatch->items()->delete();
        $rows = collect($items)->map(function (array $item) use ($existingItems, $allocationItems): array {
            $existing = $existingItems->first(function ($row) use ($item): bool {
                if (filled($item['requisition_issuance_item_id'] ?? null)) {
                    return (int) $row->requisition_issuance_item_id === (int) $item['requisition_issuance_item_id'];
                }

                return strcasecmp(trim((string) $row->item_name), trim((string) ($item['item_name'] ?? ''))) === 0
                    && (blank($item['warehouse_name'] ?? null)
                        || strcasecmp(trim((string) $row->warehouse_name), trim((string) $item['warehouse_name'])) === 0);
            });
            $allocation = $allocationItems->first(fn ($row): bool => strcasecmp(trim((string) $row->item_name), trim((string) ($item['item_name'] ?? ''))) === 0
                && (blank($item['warehouse_name'] ?? null)
                    || strcasecmp(trim((string) $row->warehouse_name), trim((string) $item['warehouse_name'])) === 0)
            );

            return [
                // Stage forms may submit quantities without repeating immutable IDs.
                // Preserve them so release always deducts the selected WIT batch/warehouse.
                'requisition_issuance_item_id' => $item['requisition_issuance_item_id'] ?? $existing?->requisition_issuance_item_id ?? $allocation?->id,
                'request_item_id' => $item['request_item_id'] ?? $existing?->request_item_id ?? $allocation?->request_item_id,
                'warehouse_id' => $item['warehouse_id'] ?? $existing?->warehouse_id ?? $allocation?->warehouse_id,
                'item_name' => $item['item_name'],
                'unit' => $item['unit'] ?? null,
                'warehouse_name' => $item['warehouse_name'] ?? null,
                'allocated_quantity' => (int) ($item['allocated_quantity'] ?? 0),
                'loaded_quantity' => array_key_exists('loaded_quantity', $item) && $item['loaded_quantity'] !== null && $item['loaded_quantity'] !== ''
                    ? (int) $item['loaded_quantity']
                    : null,
                'received_quantity' => array_key_exists('received_quantity', $item) && $item['received_quantity'] !== null && $item['received_quantity'] !== ''
                    ? (int) $item['received_quantity']
                    : null,
                'unit_cost' => array_key_exists('unit_cost', $item) && $item['unit_cost'] !== null && $item['unit_cost'] !== ''
                    ? round((float) $item['unit_cost'], 2)
                    : ($existing?->unit_cost ?? $allocation?->unit_cost ?? $this->allocationUnitCost($item['remarks'] ?? null)),
                'variance_disposition' => $item['variance_disposition'] ?? null,
                'variance_resolution' => $item['variance_resolution'] ?? $item['return_reason'] ?? null,
                'return_condition' => $item['return_condition'] ?? null,
                'return_stock_disposition' => $item['return_stock_disposition'] ?? null,
                'return_received_at' => $item['return_received_at'] ?? null,
                'return_inspected_by' => $item['return_inspected_by'] ?? null,
                'return_reason' => $item['return_reason'] ?? null,
                'remarks' => $item['remarks'] ?? null,
            ];
        })->all();

        if ($rows !== []) {
            $dispatch->items()->createMany($rows);
        }
    }

    private function itemsFromSlip(RequisitionIssuanceSlip $slip): Collection
    {
        $allocation = $slip->relationLoaded('allocationItems')
            ? $slip->allocationItems
            : $slip->allocationItems()->get();

        if ($allocation->isNotEmpty()) {
            return $allocation->map(fn ($item) => [
                'requisition_issuance_item_id' => $item->id,
                'request_item_id' => $item->request_item_id,
                'warehouse_id' => $item->warehouse_id,
                'item_name' => $item->item_name,
                'unit' => $item->unit,
                'warehouse_name' => $item->warehouse_name,
                'brand_description' => $item->brand_description,
                'expiry' => $item->expiry,
                // Preserve the RIS allocation snapshot throughout Dispatch. These
                // must not be replaced by a later live warehouse balance.
                'current_stockpile' => $item->wit_stock_balance,
                'available_to_plan' => $item->allocation_guide ?? $item->wit_stock_balance,
                'unit_cost' => $item->unit_cost ?? $this->allocationUnitCost($item->remarks),
                'allocated_quantity' => (int) $item->quantity,
                'loaded_quantity' => null,
                'received_quantity' => null,
                'remarks' => $item->remarks,
            ]);
        }

        return collect(is_array($slip->items) ? $slip->items : [])->map(fn (array $item) => [
            'requisition_issuance_item_id' => null,
            'request_item_id' => $item['request_item_id'] ?? null,
            'warehouse_id' => $item['warehouse_id'] ?? null,
            'item_name' => $item['item_name'] ?? 'Item',
            'unit' => $item['unit'] ?? null,
            'warehouse_name' => $item['warehouse_name'] ?? null,
            'brand_description' => $item['brand_description'] ?? $item['brand'] ?? null,
            'expiry' => $item['expiry'] ?? null,
            'current_stockpile' => $item['wit_stock_balance'] ?? $item['current_stockpile'] ?? null,
            'available_to_plan' => $item['allocation_guide'] ?? $item['available_to_plan'] ?? $item['available_stock'] ?? $item['wit_stock_balance'] ?? null,
            'unit_cost' => $item['unit_cost'] ?? $item['unit_price'] ?? $this->allocationUnitCost($item['remarks'] ?? null),
            'allocated_quantity' => (int) ($item['quantity'] ?? 0),
            'loaded_quantity' => null,
            'received_quantity' => null,
            'remarks' => $item['remarks'] ?? null,
        ]);
    }

    private function allocationUnitCost(?string $remarks): ?float
    {
        if (blank($remarks) || ! preg_match('/Unit prices?:\s*(?:₱|PHP|P)?\s*([\d,]+(?:\.\d+)?)/iu', $remarks, $matches)) {
            return null;
        }

        return (float) str_replace(',', '', $matches[1]);
    }

    private function syncRequestWorkflowStatus(DispatchPlan $dispatch): void
    {
        $dispatch->loadMissing('items');
        $hasDeferred = $dispatch->items->contains(fn (DispatchPlanItem $item): bool =>
            in_array($item->variance_disposition, DispatchPlan::REPLACEMENT_REQUIRED_DISPOSITIONS, true)
            && (int) $item->allocated_quantity > (int) ($item->received_quantity ?? 0));
        $requestStatus = match ($dispatch->status) {
            DispatchPlan::STATUS_RECEIVED => $hasDeferred ? 'released' : 'completed',
            DispatchPlan::STATUS_RELEASED, DispatchPlan::STATUS_IN_TRANSIT => 'released',
            default => null,
        };

        if ($requestStatus) {
            AssistanceRequest::whereKey($dispatch->request_id)->update(['status' => $requestStatus]);
        }
    }

    private function syncRisDeliveryCompletion(DispatchPlan $dispatch): void
    {
        if ($dispatch->status !== DispatchPlan::STATUS_RECEIVED) {
            return;
        }
        $dispatch->loadMissing(['items', 'requisitionIssuanceSlip']);
        $slip = $dispatch->requisitionIssuanceSlip;
        if (! $slip) {
            return;
        }
        $hasDeferred = $dispatch->items->contains(fn (DispatchPlanItem $item): bool =>
            in_array($item->variance_disposition, DispatchPlan::REPLACEMENT_REQUIRED_DISPOSITIONS, true)
            && (int) $item->allocated_quantity > (int) ($item->received_quantity ?? 0));
        if ($hasDeferred && $slip->status === 'completed') {
            $slip->update(['status' => 'approved']);
            return;
        }
        if (! $hasDeferred && $slip->status === 'approved' && $slip->hasCompletePostRisData()) {
            $slip->update(['status' => 'completed']);
        }
        if (! $hasDeferred && $dispatch->parent_dispatch_plan_id) {
            DispatchPlanItem::query()
                ->where('dispatch_plan_id', $dispatch->parent_dispatch_plan_id)
                ->whereIn('variance_disposition', DispatchPlan::REPLACEMENT_REQUIRED_DISPOSITIONS)
                ->update([
                    'variance_disposition' => 'fulfilled_followup',
                    'variance_resolution' => 'Delivered through follow-up dispatch '.$dispatch->dispatch_number.'.',
                ]);
        }
    }

    private function publishRealtime(RealtimePublisher $realtime, DispatchPlan $dispatch, string $event): void
    {
        $userIds = User::permission('manage dispatches')->where('is_active', true)->pluck('id');
        if ($userIds->isEmpty()) {
            $userIds = User::role(['RROS', 'Super Admin'])->where('is_active', true)->pluck('id');
        }

        $realtime->usersChanged(
            $userIds
                ->merge(User::role(['RROS', 'RROS AA'])->pluck('id'))
                ->merge($this->assignedEscortUserIds($dispatch))
                ->unique()->values(),
            $event,
            [
                'dispatch_id' => $dispatch->id,
                'request_id' => $dispatch->request_id,
                'status' => $dispatch->status,
            ],
        );
    }

    private function publishDeliveryUpdateRealtime(RealtimePublisher $realtime, DispatchPlan $dispatch): void
    {
        $userIds = User::permission('manage dispatches')->where('is_active', true)->pluck('id')
            ->merge(User::role(['RROS', 'RROS AA'])->where('is_active', true)->pluck('id'))
            ->merge($this->assignedEscortUserIds($dispatch));
        $realtime->usersChanged($userIds->unique()->values(), 'dispatch.delivery.update.created', [
            'dispatch_id' => $dispatch->id,
            'request_id' => $dispatch->request_id,
            'status' => $dispatch->status,
        ]);
    }

    private function assignedEscortUserIds(DispatchPlan $dispatch): Collection
    {
        $identifiers = collect($dispatch->resolvedVehicleDetails())->flatMap(fn (array $vehicle): array => array_filter([
            $vehicle['escort_id_number'] ?? null,
            $vehicle['escort_name'] ?? null,
        ]))->map(fn ($value) => Str::lower(trim((string) $value)))->unique();
        if ($identifiers->isEmpty()) return collect();
        return User::query()->where('is_active', true)->get(['id', 'name', 'id_number'])
            ->filter(fn (User $user): bool => $identifiers->contains(Str::lower(trim((string) $user->id_number)))
                || $identifiers->contains(Str::lower(trim((string) $user->name))))
            ->pluck('id');
    }

    private function serializeSlipSummary(?RequisitionIssuanceSlip $slip, ?AssistanceRequest $request = null): ?array
    {
        if (! $slip) {
            return null;
        }

        $tracking = is_array($slip->tracking_data) ? $slip->tracking_data : [];
        $receivingRepresentative = $slip->receiving_representative
            ?: ($tracking['receiving_representative'] ?? null);
        $contactNumber = $slip->contact_number
            ?: ($tracking['contact_number'] ?? null);
        $recipient = $slip->recipient ?: ($tracking['recipient'] ?? null);
        $assistanceRequest = $request
            ?: ($slip->relationLoaded('request') ? $slip->request : null)
            ?: (filled($slip->request_id) ? $slip->request()->first() : null);
        $profile = $this->resolveReceivingRepresentativeProfile(
            is_string($receivingRepresentative) ? $receivingRepresentative : null,
            $assistanceRequest instanceof AssistanceRequest ? $assistanceRequest : null,
            is_string($recipient) ? $recipient : null,
        );

        return [
            'id' => $slip->id,
            'ris_number' => $slip->ris_number,
            'ris_drn' => $slip->ris_drn ?: ($tracking['ris_drn'] ?? null),
            'dr_number' => $slip->dr_number ?: ($tracking['dr_number'] ?? null),
            'status' => $slip->status,
            'reservation_status' => $slip->reservation_status,
            'ris_date' => optional($slip->ris_date)?->format('Y-m-d'),
            'purpose_of_release' => $slip->purpose_of_release,
            'prepared_at' => optional($slip->updated_at)?->toIso8601String(),
            'recipient' => $recipient,
            'delivery_site' => $slip->delivery_site,
            'receiving_representative' => $receivingRepresentative,
            'contact_number' => filled($contactNumber)
                ? $contactNumber
                : ($profile['contact_number'] ?? null),
            'receiving_representative_position' => $profile['position'] ?? null,
            'receiving_representative_office' => $profile['office'] ?? null,
            'receiving_representative_id_number' => $profile['id_number'] ?? null,
            // Legacy post-RIS delivery fields (for Dispatch / Delivery prefill / print fallback).
            'delivered_at' => optional($slip->delivered_at)?->format('Y-m-d') ?: ($tracking['delivered_at'] ?? null),
            'release_witnessed_by' => $slip->release_witnessed_by ?: ($tracking['release_witnessed_by'] ?? null),
            'fully_delivered' => $this->yesNoLabel($slip->fully_delivered ?? ($tracking['fully_delivered'] ?? null)),
            'has_returned_items' => $this->yesNoLabel($slip->has_returned_items ?? ($tracking['has_returned_items'] ?? null)),
            'returned_particulars' => $slip->returned_particulars ?: ($tracking['returned_particulars'] ?? null),
            'returned_quantity' => $slip->returned_quantity ?? ($tracking['returned_quantity'] ?? null),
            'returned_reason' => $slip->returned_reason ?: ($tracking['returned_reason'] ?? null),
        ];
    }

    private function serializeEligibleRequest(AssistanceRequest $request): array
    {
        $slip = $request->requisitionIssuanceSlip;
        $tracking = is_array($slip?->tracking_data) ? $slip->tracking_data : [];
        $summary = $this->serializeSlipSummary($slip, $request);
        $source = $request->relationLoaded('sourceLguDromicReport')
            ? $request->sourceLguDromicReport
            : $request->sourceLguDromicReport()->first([
                'id',
                'reference_number',
                'lgu_relief_request_reference',
                'lgu_signed_request_path',
                'lgu_signed_report_path',
            ]);

        $signedDocs = $request->relationLoaded('epirmaSignedDocuments')
            ? $request->epirmaSignedDocuments
            : $request->epirmaSignedDocuments()
                ->where('routing_status', 'signed')
                ->orderByDesc('id')
                ->get();
        $assessmentDoc = $signedDocs->firstWhere('document_type', 'assessment')
            ?: $signedDocs->first(fn ($doc) => ($doc->document_type ?? 'assessment') === 'assessment');

        // Relative same-origin paths (match RROS Requests/Index) so iframes are not
        // broken by APP_URL scheme/host mismatch (e.g. https APP_URL on http Herd).
        $signedAssessmentViewUrl = $assessmentDoc?->id
            ? "/requests/{$request->id}/epirma/documents/{$assessmentDoc->id}/view"
            : null;

        $lguRequestViewUrl = ($source?->id && filled($source->lgu_signed_request_path))
            ? "/lgu/dromic-sitrep/{$source->id}/signed-copy/request"
            : (filled($request->source_document_url)
                ? "/requests/{$request->id}/source-document"
                : null);

        $risViewUrl = ($slip && filled($slip->ris_drn) && filled($slip->ris_link))
            ? "/rros/ris/{$slip->id}/documents/ris"
            : null;
        $hasDr = $slip && (
            filled($slip->dr_number) || filled($tracking['dr_number'] ?? null)
        );

        $previewItems = $slip
            ? $this->itemsFromSlip($slip)->map(fn (array $item) => [
                'item_name' => $item['item_name'] ?? 'Item',
                'unit' => $item['unit'] ?? null,
                'quantity' => (int) ($item['allocated_quantity'] ?? 0),
                'remarks' => $item['remarks'] ?? null,
                'warehouse_name' => $item['warehouse_name'] ?? null,
                'warehouse_id' => $item['warehouse_id'] ?? null,
            ])->values()
            : collect();

        return [
            'id' => $request->id,
            'reference_number' => $request->reference_number,
            'status' => $request->status,
            'requesting_agency' => $request->requesting_agency,
            'lgu' => $request->lgu,
            'municipality' => $request->municipality,
            'province' => $request->province,
            'lgu_psgc_code' => $request->lgu_psgc_code,
            'requester' => $request->requester,
            'purpose' => $request->purpose,
            'source_document_url' => $request->source_document_url,
            'source_lgu_dromic_report' => $source ? [
                'id' => $source->id,
                'lgu_relief_request_reference' => $source->lgu_relief_request_reference,
                'lgu_signed_request_path' => $source->lgu_signed_request_path,
                'lgu_signed_report_path' => $source->lgu_signed_report_path,
            ] : null,
            'lgu_request_view_url' => $lguRequestViewUrl,
            'signed_assessment_view_url' => $signedAssessmentViewUrl,
            'assessment_pdf_view_url' => "/requests/{$request->id}/assessment-pdf?margin=18&inline=1",
            'ris_view_url' => $risViewUrl,
            'signed_ris_view_url' => ($slip?->approval_routing_mode === 'epirma' && $slip?->ris_epirma_status === 'signed')
                ? "/rros/ris/{$slip->id}/epirma/signed-preview"
                : null,
            'ris_advance_pdf_view_url' => $slip ? "/rros/ris/{$slip->id}/preview-pdf/ris?inline=1" : null,
            'dr_advance_pdf_view_url' => ($slip && $hasDr) ? "/rros/ris/{$slip->id}/preview-pdf/dr?inline=1" : null,
            'ris_slip_id' => $slip?->id,
            'ris_preview' => $slip ? [
                'form' => [
                    'ris_number' => $slip->ris_number,
                    'ris_date' => optional($slip->ris_date)?->format('Y-m-d'),
                    'purpose_of_release' => $slip->purpose_of_release,
                    'recipient' => $slip->recipient,
                    'delivery_site' => $slip->delivery_site,
                    'receiving_representative' => $slip->receiving_representative,
                    'contact_number' => $slip->contact_number,
                    'remarks' => $slip->remarks,
                    'items' => $previewItems,
                ],
                'tracking' => [
                    ...$tracking,
                    'ris_drn' => $slip->ris_drn ?: ($tracking['ris_drn'] ?? null),
                    'dr_number' => $slip->dr_number ?: ($tracking['dr_number'] ?? null),
                    'prepared_by_name' => $slip->prepared_by_name ?: ($tracking['prepared_by_name'] ?? null),
                    'release_witnessed_by' => $slip->release_witnessed_by ?: ($tracking['release_witnessed_by'] ?? null),
                    'delivered_at' => optional($slip->delivered_at)?->format('Y-m-d') ?: ($tracking['delivered_at'] ?? null),
                    'returned_particulars' => $slip->returned_particulars ?: ($tracking['returned_particulars'] ?? null),
                    'returned_quantity' => $slip->returned_quantity ?? ($tracking['returned_quantity'] ?? null),
                    'returned_reason' => $slip->returned_reason ?: ($tracking['returned_reason'] ?? null),
                    // Printable transport is merged client-side from the active Dispatch Plan.
                    'driver_name' => null,
                    'driver_contact_number' => null,
                    'vehicle_plate_number' => null,
                    'mode_of_transportation' => [],
                ],
                'has_dr' => filled($slip->dr_number ?: ($tracking['dr_number'] ?? null)),
            ] : null,
            'ris' => $summary ? [
                ...$summary,
                'receiving_representative' => $slip->receiving_representative,
                // Legacy RIS transport keys may still seed a new Dispatch Plan form.
                'driver_name' => $slip->driver_name ?: ($tracking['driver_name'] ?? null),
                'driver_contact_number' => $slip->driver_contact_number ?: ($tracking['driver_contact_number'] ?? null),
                'vehicle_plate_number' => $slip->vehicle_plate_number ?: ($tracking['vehicle_plate_number'] ?? null),
                'mode_of_transportation' => $this->asStringList($tracking['mode_of_transportation'] ?? null),
                'vehicle_types' => $this->asStringList($tracking['vehicle_type'] ?? $tracking['vehicle_types'] ?? null),
                'number_of_vehicles' => $tracking['number_of_vehicles'] ?? $tracking['no_of_vehicles'] ?? null,
                'items' => $this->itemsFromSlip($slip)->values(),
            ] : null,
        ];
    }

    private function serializeDispatch(DispatchPlan $dispatch, ?User $viewer = null, bool $monitorAllVehicles = false): array
    {
        $dispatch->loadMissing(['creator:id,name', 'updater:id,name', 'request', 'requisitionIssuanceSlip', 'items', 'deliveryUpdates.reporter:id,name']);
        $legacy = $this->serializeSlipSummary($dispatch->requisitionIssuanceSlip, $dispatch->request) ?? [];
        $documentPreview = $dispatch->request
            ? $this->serializeEligibleRequest($dispatch->request)
            : null;
        $allVehicles = collect($dispatch->resolvedVehicleDetails())->values();
        $visibleVehicleIndexes = $viewer && ! $monitorAllVehicles && ! $this->canManageDispatches($viewer)
            ? $this->authorizedVehicleIndexes($dispatch, $viewer)
            : $allVehicles->keys()->map(fn ($index): int => (int) $index)->all();
        $visibleVehicles = $allVehicles->only($visibleVehicleIndexes);
        $visibleItemIds = $visibleVehicles->flatMap(fn (array $vehicle) => collect($vehicle['loaded_items'] ?? [])
            ->pluck('requisition_issuance_item_id'))->filter()->map(fn ($id) => (string) $id)->unique();
        $visibleItems = ($viewer && ! $monitorAllVehicles && ! $this->canManageDispatches($viewer))
            ? $dispatch->items->filter(fn (DispatchPlanItem $item): bool => $visibleItemIds->contains((string) $item->requisition_issuance_item_id))
            : $dispatch->items;
        $allocatedTotal = (int) $visibleItems->sum('allocated_quantity');
        $receivedTotal = (int) $visibleItems->sum(fn (DispatchPlanItem $item): int => (int) ($item->received_quantity ?? 0));
        $deferredTotal = (int) $visibleItems
            ->whereIn('variance_disposition', DispatchPlan::REPLACEMENT_REQUIRED_DISPOSITIONS)
            ->sum(fn (DispatchPlanItem $item): int => max(0, (int) $item->allocated_quantity - (int) ($item->received_quantity ?? 0)));
        $allocationItems = $dispatch->requisitionIssuanceSlip
            ? $this->itemsFromSlip($dispatch->requisitionIssuanceSlip)->keyBy('requisition_issuance_item_id')
            : collect();

        return [
            'id' => $dispatch->id,
            'parent_dispatch_plan_id' => $dispatch->parent_dispatch_plan_id,
            'delivery_sequence' => (int) $dispatch->delivery_sequence,
            'dr_series_offset' => (int) $dispatch->dr_series_offset,
            'dispatch_number' => $dispatch->dispatch_number,
            'request_id' => $dispatch->request_id,
            'requisition_issuance_slip_id' => $dispatch->requisition_issuance_slip_id,
            'status' => $dispatch->status,
            'can_edit_delivery_updates' => $viewer
                ? ($this->deliveryUpdateDevelopmentAccess() || $this->isAssignedEscort($dispatch, $viewer))
                : false,
            'editable_delivery_vehicle_indexes' => $viewer && $this->deliveryUpdateDevelopmentAccess()
                ? $allVehicles->keys()->map(fn ($index): int => (int) $index)->all()
                : ($viewer && $this->isAssignedEscort($dispatch, $viewer)
                    ? $this->authorizedVehicleIndexes($dispatch, $viewer)
                    : []),
            // Receipt revisions are intentionally broader than movement-update
            // authorship: the assigned escort or an authorized dispatch officer
            // may correct receipt details while the audit trail retains history.
            'can_edit_receipts' => $viewer
                ? ($this->canManageDispatches($viewer) || $this->isAssignedEscort($dispatch, $viewer))
                : false,
            'editable_receipt_vehicle_indexes' => $viewer && $this->canManageDispatches($viewer)
                ? $allVehicles->keys()->map(fn ($index): int => (int) $index)->all()
                : ($viewer && $this->isAssignedEscort($dispatch, $viewer)
                    ? $this->authorizedVehicleIndexes($dispatch, $viewer)
                    : []),
            'updated_at' => optional($dispatch->updated_at)?->toIso8601String(),
            'fulfillment_type' => $dispatch->fulfillment_type
                ?: DispatchPlan::FULFILLMENT_FIELD_DELIVERY,
            'fulfillment_type_confirmed' => (bool) $dispatch->fulfillment_type_confirmed,
            'local_handover_details' => [
                ...($dispatch->local_handover_details ?? []),
                'dr_number' => filled(data_get($dispatch->local_handover_details, 'released_at'))
                    ? data_get($dispatch->local_handover_details, 'dr_number')
                    : null,
            ],
            'bucket' => $dispatch->bucket(),
            'fulfillment_status' => $deferredTotal > 0
                ? 'partially_delivered'
                : ($dispatch->status === DispatchPlan::STATUS_RECEIVED ? 'fulfilled_or_resolved' : 'in_progress'),
            'fulfillment_summary' => [
                'allocated' => $allocatedTotal,
                'received' => $receivedTotal,
                'outstanding' => max(0, $allocatedTotal - $receivedTotal),
                'deferred' => $deferredTotal,
            ],
            'destination' => $dispatch->destination,
            'receiving_agency_lgu' => $dispatch->receiving_agency_lgu,
            'source_of_goods' => $dispatch->source_of_goods,
            'purpose' => $dispatch->purpose,
            'driver' => $dispatch->driver,
            'driver_contact_number' => $dispatch->driver_contact_number,
            'vehicle_plate_number' => $dispatch->vehicle_plate_number,
            'dispatcher' => $dispatch->dispatcher,
            'dispatch_officer' => $dispatch->dispatcher,
            'mode_of_transportation' => $dispatch->mode_of_transportation ?? [],
            'vehicle_types' => $dispatch->vehicle_types ?? [],
            'number_of_vehicles' => $visibleVehicles->count(),
            'vehicle_details' => $visibleVehicles->map(fn (array $vehicle, int $index): array => [
                ...$vehicle,
                'dr_number' => filled($vehicle['warehouse_released_at'] ?? null) ? ($vehicle['dr_number'] ?? null) : null,
                'source_vehicle_index' => $index,
            ])->values()->all(),
            'dr_documents' => $visibleVehicles->map(fn (array $vehicle, int $index): array => [
                'vehicle_index' => $index,
                'dr_number' => filled($vehicle['warehouse_released_at'] ?? null) ? ($vehicle['dr_number'] ?? null) : null,
                'vehicle' => $vehicle['vehicle_plate_number'] ?? $vehicle['vehicle_type'] ?? 'Vehicle '.($index + 1),
                'driver' => $vehicle['driver'] ?? null,
                'escort' => $vehicle['escort_name'] ?? null,
                'preview_url' => filled($vehicle['warehouse_released_at'] ?? null) && filled($vehicle['dr_number'] ?? null) && $this->vehicleIsAssignedForDr($vehicle)
                    ? route('dispatches.vehicles.dr', ['dispatch' => $dispatch->id, 'vehicleIndex' => $index])
                    : null,
            ])->when(filled(data_get($dispatch->local_handover_details, 'released_at')) && filled(data_get($dispatch->local_handover_details, 'dr_number')), fn (Collection $documents) => $documents->push([
                'vehicle_index' => null,
                'dr_number' => data_get($dispatch->local_handover_details, 'dr_number'),
                'vehicle' => 'No transport needed',
                'driver' => null,
                'escort' => null,
                'is_local_handover' => true,
                'preview_url' => route('dispatches.local-handover.dr', ['dispatch' => $dispatch->id]),
            ]))->values()->all(),
            'delivery_updates' => $dispatch->deliveryUpdates
                ->filter(fn (DispatchDeliveryUpdate $update): bool => in_array((int) $update->vehicle_index, $visibleVehicleIndexes, true))
                ->map(fn (DispatchDeliveryUpdate $update): array => [
                'id' => $update->id,
                'vehicle_index' => array_search((int) $update->vehicle_index, $visibleVehicleIndexes, true),
                'stage' => $update->stage,
                'occurred_at' => optional($update->occurred_at)?->toIso8601String(),
                'location' => $update->location,
                'latitude' => $update->latitude,
                'longitude' => $update->longitude,
                'accuracy_meters' => $update->accuracy_meters,
                'message' => $update->message,
                'reporter_name' => $update->reporter?->name,
                'reporter_role' => $update->reporter_role,
                'photos' => collect($update->photo_paths ?? [])->keys()->map(fn (int $index): array => [
                    'url' => route('dispatches.delivery-updates.photos.show', [$dispatch, $update, $index]),
                    'label' => 'Delivery photo '.($index + 1),
                ])->values(),
            ])->values(),
            'vehicle_id' => $dispatch->vehicle_id,
            'dispatch_date' => optional($dispatch->dispatch_date)?->format('Y-m-d'),
            'estimated_arrival' => optional($dispatch->estimated_arrival)?->format('Y-m-d\TH:i'),
            'actual_arrival' => optional($dispatch->actual_arrival)?->format('Y-m-d\TH:i'),
            'warehouse_released_at' => optional($dispatch->warehouse_released_at)?->format('Y-m-d\TH:i'),
            'warehouse_released_by' => $dispatch->warehouse_released_by,
            'loaded_at' => optional($dispatch->loaded_at)?->format('Y-m-d\TH:i'),
            'loading_remarks' => $dispatch->loading_remarks,
            'departed_at' => optional($dispatch->departed_at)?->format('Y-m-d\TH:i'),
            'received_by' => $dispatch->received_by,
            'received_at' => optional($dispatch->received_at)?->format('Y-m-d\TH:i'),
            'receiver_contact' => $dispatch->receiver_contact,
            'receipt_acknowledged' => (bool) $dispatch->receipt_acknowledged,
            'receipt_remarks' => $dispatch->receipt_remarks,
            'delivered_at' => optional($dispatch->delivered_at)?->format('Y-m-d')
                ?: ($legacy['delivered_at'] ?? null),
            'release_witnessed_by' => $dispatch->release_witnessed_by
                ?: ($legacy['release_witnessed_by'] ?? null),
            'fully_delivered' => $this->yesNoLabel(
                $dispatch->fully_delivered !== null
                    ? $dispatch->fully_delivered
                    : ($legacy['fully_delivered'] ?? null)
            ),
            'has_returned_items' => $this->yesNoLabel(
                $dispatch->has_returned_items !== null
                    ? $dispatch->has_returned_items
                    : ($legacy['has_returned_items'] ?? null)
            ),
            'returned_particulars' => $dispatch->returned_particulars
                ?: ($legacy['returned_particulars'] ?? null),
            'returned_quantity' => $dispatch->returned_quantity
                ?? ($legacy['returned_quantity'] ?? null),
            'returned_reason' => $dispatch->returned_reason
                ?: ($legacy['returned_reason'] ?? null),
            'remarks' => $dispatch->remarks,
            'created_by' => $dispatch->created_by,
            'updated_by' => $dispatch->updated_by,
            'created_by_name' => $dispatch->creator?->name,
            'updated_by_name' => $dispatch->updater?->name,
            'creator' => $dispatch->creator ? [
                'id' => $dispatch->creator->id,
                'name' => $dispatch->creator->name,
            ] : null,
            'updater' => $dispatch->updater ? [
                'id' => $dispatch->updater->id,
                'name' => $dispatch->updater->name,
            ] : null,
            'status_timeline' => $this->serializeStatusTimeline($dispatch->status_timeline ?? []),
            'history' => $this->serializeStatusTimeline($dispatch->status_timeline ?? []),
            'document_preview' => $documentPreview,
            'request' => $dispatch->request ? [
                'id' => $dispatch->request->id,
                'reference_number' => $dispatch->request->reference_number,
                'status' => $dispatch->request->status,
                'requesting_agency' => $dispatch->request->requesting_agency,
                'lgu' => $dispatch->request->lgu,
                'municipality' => $dispatch->request->municipality,
                'province' => $dispatch->request->province,
                'lgu_level' => $dispatch->request->lgu_level,
                'lgu_psgc_code' => $dispatch->request->lgu_psgc_code,
                'requester' => $dispatch->request->requester,
                'purpose' => $dispatch->request->purpose,
            ] : null,
            'ris' => $this->serializeSlipSummary($dispatch->requisitionIssuanceSlip, $dispatch->request),
            'items' => $visibleItems->map(function (DispatchPlanItem $item) use ($allocationItems): array {
                $allocation = $allocationItems->get($item->requisition_issuance_item_id, []);

                return [
                'id' => $item->id,
                'requisition_issuance_item_id' => $item->requisition_issuance_item_id,
                'request_item_id' => $item->request_item_id,
                'warehouse_id' => $item->warehouse_id,
                'item_name' => $item->item_name,
                'unit' => $item->unit,
                'warehouse_name' => $item->warehouse_name,
                'brand_description' => $allocation['brand_description'] ?? null,
                'expiry' => $allocation['expiry'] ?? null,
                'current_stockpile' => $allocation['current_stockpile'] ?? null,
                'available_to_plan' => $allocation['available_to_plan'] ?? null,
                'unit_cost' => $allocation['unit_cost'] ?? null,
                'allocated_quantity' => $item->allocated_quantity,
                'loaded_quantity' => $item->loaded_quantity,
                'received_quantity' => $item->received_quantity,
                'variance_disposition' => $item->variance_disposition,
                'variance_resolution' => $item->variance_resolution,
                'return_condition' => $item->return_condition,
                'return_stock_disposition' => $item->return_stock_disposition,
                'return_received_at' => optional($item->return_received_at)?->format('Y-m-d\TH:i'),
                'return_inspected_by' => $item->return_inspected_by,
                'return_reason' => $item->return_reason,
                'remarks' => $item->remarks,
                ];
            })->values(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>|mixed  $timeline
     * @return list<array<string, mixed>>
     */
    private function serializeStatusTimeline(mixed $timeline): array
    {
        $entries = collect(is_array($timeline) ? $timeline : [])->values();
        $userIds = $entries
            ->map(fn ($entry) => is_array($entry) ? ($entry['by'] ?? null) : null)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $namesById = $userIds === []
            ? []
            : User::query()->whereIn('id', $userIds)->pluck('name', 'id')->all();

        return $entries
            ->filter(fn ($entry) => is_array($entry))
            ->map(function (array $entry) use ($namesById): array {
                $by = isset($entry['by']) ? (int) $entry['by'] : null;
                $from = $entry['from_status'] ?? null;
                $to = $entry['to_status'] ?? ($entry['status'] ?? null);

                return array_filter([
                    'type' => $entry['type'] ?? 'status_change',
                    'status' => $to,
                    'from_status' => $from,
                    'to_status' => $to,
                    'at' => $entry['at'] ?? null,
                    'by' => $by,
                    'by_name' => $entry['by_name']
                        ?? ($by ? ($namesById[$by] ?? null) : null),
                    'note' => $entry['note'] ?? null,
                ], fn ($value) => $value !== null && $value !== '');
            })
            ->values()
            ->all();
    }

    private function asStringList(mixed $value): array
    {
        if (is_array($value)) {
            return collect($value)->map(fn ($item) => trim((string) $item))->filter()->values()->all();
        }

        if ($value === null || $value === '') {
            return [];
        }

        $text = trim((string) $value);
        if (str_contains($text, ',')) {
            return collect(explode(',', $text))->map(fn ($item) => trim($item))->filter()->values()->all();
        }

        return [$text === 'Partner LGU' ? 'Partner' : $text];
    }

    /**
     * Normalize multi-vehicle ops rows and mirror vehicles[0] onto legacy plan columns.
     * Fleet linkage (vehicle_id) is not written from dispatch planning.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeVehiclePayload(array $data): array
    {
        $requestedCount = null;
        if (array_key_exists('number_of_vehicles', $data) && $data['number_of_vehicles'] !== null && $data['number_of_vehicles'] !== '') {
            $requestedCount = max(0, min(99, (int) $data['number_of_vehicles']));
        }

        $emptyRow = fn (): array => $this->normalizeVehicleDetailArray([]);

        $rows = collect($data['vehicle_details'] ?? [])
            ->filter(fn ($row) => is_array($row))
            ->map(fn (array $row) => $this->normalizeVehicleDetailArray($row))
            ->values();

        if ($requestedCount === 0) {
            $data['vehicle_details'] = [];
            $data['number_of_vehicles'] = 0;
            $data['vehicle_id'] = null;

            return $data;
        }

        if ($rows->isEmpty()) {
            $legacyTypes = $this->asStringList($data['vehicle_types'] ?? null);
            $legacyModes = $this->asStringList($data['mode_of_transportation'] ?? null);
            $hasLegacy = filled($data['driver'] ?? null)
                || filled($data['driver_contact_number'] ?? null)
                || filled($data['vehicle_plate_number'] ?? null)
                || filled($data['dispatch_date'] ?? null)
                || filled($data['estimated_departure'] ?? null)
                || $legacyTypes !== []
                || $legacyModes !== []
                || ($requestedCount !== null && $requestedCount > 0);

            if ($hasLegacy) {
                $rows = collect([$this->normalizeVehicleDetailArray([
                    'vehicle_type' => $legacyTypes[0] ?? null,
                    'driver' => $data['driver'] ?? null,
                    'driver_contact_number' => $data['driver_contact_number'] ?? null,
                    'vehicle_plate_number' => $data['vehicle_plate_number'] ?? null,
                    'estimated_departure' => $data['estimated_departure']
                        ?? ($data['dispatch_date'] ?? null),
                    'estimated_arrival' => $data['estimated_arrival'] ?? null,
                    'mode_of_transportation' => $legacyModes,
                    'warehouse_released_at' => $data['warehouse_released_at'] ?? null,
                    'warehouse_released_by' => $data['warehouse_released_by'] ?? null,
                    'release_witnessed_by' => $data['release_witnessed_by'] ?? null,
                    'loaded_at' => $data['loaded_at'] ?? null,
                    'loading_remarks' => $data['loading_remarks'] ?? null,
                    'departed_at' => $data['departed_at'] ?? null,
                    'actual_arrival' => $data['actual_arrival'] ?? null,
                    'delivered_at' => $data['delivered_at'] ?? null,
                    'fully_delivered' => $data['fully_delivered'] ?? null,
                    'received_by' => $data['received_by'] ?? null,
                    'received_at' => $data['received_at'] ?? null,
                    'receiver_contact' => $data['receiver_contact'] ?? null,
                    'receipt_acknowledged' => $data['receipt_acknowledged'] ?? false,
                    'receipt_remarks' => $data['receipt_remarks'] ?? null,
                ])]);

                $target = $requestedCount ?? 1;
                while ($rows->count() < $target) {
                    $index = $rows->count();
                    $rows->push($this->normalizeVehicleDetailArray([
                        'vehicle_type' => $legacyTypes[$index] ?? null,
                    ]));
                }
            }
        }

        if ($rows->isEmpty()) {
            $data['vehicle_id'] = null;
            if ($requestedCount === null) {
                unset($data['number_of_vehicles']);
            } else {
                $data['number_of_vehicles'] = 0;
            }

            return $data;
        }

        if ($requestedCount !== null && $rows->count() !== $requestedCount) {
            if ($rows->count() < $requestedCount) {
                while ($rows->count() < $requestedCount) {
                    $rows->push($emptyRow());
                }
            } else {
                $rows = $rows->take($requestedCount)->values();
            }
        }

        $first = $rows->first();
        $data['vehicle_details'] = $rows->all();
        $data['number_of_vehicles'] = $rows->count();
        $data['vehicle_id'] = null;

        // Mirror vehicles[0] onto legacy plan columns for print / RIS / older consumers.
        $data['driver'] = $first['driver'] ?? null;
        $data['driver_contact_number'] = $first['driver_contact_number'] ?? null;
        $data['vehicle_plate_number'] = $first['vehicle_plate_number'] ?? null;
        // Legacy date column: date portion of estimated departure (vehicles[0]).
        $data['dispatch_date'] = filled($first['estimated_departure'] ?? null)
            ? substr((string) $first['estimated_departure'], 0, 10)
            : null;
        $data['estimated_arrival'] = $first['estimated_arrival'] ?? null;
        $firstMode = $first['mode_of_transportation'] ?? null;
        if (is_array($firstMode)) {
            $firstMode = $this->asStringList($firstMode)[0] ?? null;
        }
        $data['mode_of_transportation'] = filled($firstMode) ? [$firstMode] : null;
        $data['warehouse_released_at'] = $first['warehouse_released_at'] ?? null;
        $data['warehouse_released_by'] = $first['warehouse_released_by'] ?? null;
        $data['release_witnessed_by'] = $first['release_witnessed_by'] ?? null;
        $data['loaded_at'] = $first['loaded_at'] ?? null;
        $data['loading_remarks'] = $first['loading_remarks'] ?? null;
        $data['departed_at'] = $first['departed_at'] ?? null;
        $data['actual_arrival'] = $first['actual_arrival'] ?? null;
        $data['delivered_at'] = $first['delivered_at']
            ?? (filled($first['actual_arrival'] ?? null)
                ? substr((string) $first['actual_arrival'], 0, 10)
                : null);
        $data['fully_delivered'] = $this->normalizeYesNo($first['fully_delivered'] ?? null);
        $data['received_by'] = $first['received_by'] ?? null;
        $data['received_at'] = $first['received_at'] ?? null;
        $data['receiver_contact'] = $first['receiver_contact'] ?? null;
        $data['receipt_acknowledged'] = (bool) ($first['receipt_acknowledged'] ?? false);
        $data['receipt_remarks'] = $first['receipt_remarks'] ?? null;

        $types = $rows->pluck('vehicle_type')->filter()->unique()->values()->all();
        $data['vehicle_types'] = $types === [] ? null : $types;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeVehicleDetailArray(array $row): array
    {
        $mode = collect($this->asStringList($row['mode_of_transportation'] ?? null))
            ->map(fn ($value) => $value === 'Partner LGU' ? 'Partner' : $value)
            ->filter()
            ->first();

        $hasEscort = $this->vehicleHasDswdEscort($row);

        $loadedItems = collect($row['loaded_items'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item): array {
                $qty = $item['loaded_quantity'] ?? $item['loaded_qty'] ?? null;
                $planned = $item['planned_quantity'] ?? $qty;

                return [
                    'requisition_issuance_item_id' => $item['requisition_issuance_item_id'] ?? null,
                    'item_name' => filled($item['item_name'] ?? null) ? trim((string) $item['item_name']) : null,
                    'loaded_quantity' => ($qty === null || $qty === '') ? null : (int) $qty,
                    'planned_quantity' => ($planned === null || $planned === '') ? null : (int) $planned,
                    'remarks' => filled($item['remarks'] ?? $item['line_remarks'] ?? null)
                        ? trim((string) ($item['remarks'] ?? $item['line_remarks']))
                        : null,
                ];
            })
            ->values()
            ->all();
        $sourceWarehouses = collect($row['source_warehouses'] ?? [])
            ->filter(fn ($warehouse) => is_array($warehouse) && (filled($warehouse['id'] ?? null) || filled($warehouse['name'] ?? null)))
            ->map(fn (array $warehouse): array => [
                'id' => filled($warehouse['id'] ?? null) ? (int) $warehouse['id'] : null,
                'name' => filled($warehouse['name'] ?? null) ? trim((string) $warehouse['name']) : null,
            ])->unique(fn (array $warehouse): string => filled($warehouse['id']) ? 'id:'.$warehouse['id'] : 'name:'.strtolower((string) $warehouse['name']))
            ->values()->all();
        if ($sourceWarehouses === [] && (filled($row['source_warehouse_id'] ?? null) || filled($row['source_warehouse_name'] ?? null))) {
            $sourceWarehouses[] = ['id' => $row['source_warehouse_id'] ?? null, 'name' => $row['source_warehouse_name'] ?? null];
        }
        $primarySource = $sourceWarehouses[0] ?? [];

        return [
            // Retain the persisted fleet index used by filtered escort
            // workspaces. This is a transport key only; it lets scoped
            // receipt/transit commands address the correct saved vehicle.
            ...(array_key_exists('source_vehicle_index', $row)
                && $row['source_vehicle_index'] !== null
                && $row['source_vehicle_index'] !== ''
                    ? ['source_vehicle_index' => (int) $row['source_vehicle_index']]
                    : []),
            'fulfillment_type' => in_array($row['fulfillment_type'] ?? null, DispatchPlan::FULFILLMENT_TYPES, true)
                ? $row['fulfillment_type']
                : DispatchPlan::FULFILLMENT_FIELD_DELIVERY,
            'plan_confirmed_at' => filled($row['plan_confirmed_at'] ?? null) ? (string) $row['plan_confirmed_at'] : null,
            'dr_number' => filled($row['dr_number'] ?? null) ? trim((string) $row['dr_number']) : null,
            'source_warehouse_id' => filled($primarySource['id'] ?? $row['source_warehouse_id'] ?? null)
                ? (int) ($primarySource['id'] ?? $row['source_warehouse_id'])
                : null,
            'source_warehouse_name' => filled($primarySource['name'] ?? $row['source_warehouse_name'] ?? null)
                ? trim((string) ($primarySource['name'] ?? $row['source_warehouse_name']))
                : null,
            'source_warehouses' => $sourceWarehouses,
            'vehicle_type' => filled($row['vehicle_type'] ?? null) ? trim((string) $row['vehicle_type']) : null,
            'driver' => filled($row['driver'] ?? null) ? trim((string) $row['driver']) : null,
            'driver_contact_number' => filled($row['driver_contact_number'] ?? null)
                ? trim((string) $row['driver_contact_number'])
                : null,
            'driver_id_number' => filled($row['driver_id_number'] ?? null)
                ? trim((string) $row['driver_id_number'])
                : null,
            'driver_position' => filled($row['driver_position'] ?? null)
                ? trim((string) $row['driver_position'])
                : null,
            'driver_office' => filled($row['driver_office'] ?? null)
                ? trim((string) $row['driver_office'])
                : null,
            'vehicle_plate_number' => filled($row['vehicle_plate_number'] ?? null)
                ? trim((string) $row['vehicle_plate_number'])
                : null,
            'has_dswd_escort' => $hasEscort,
            'escort_name' => $hasEscort && filled($row['escort_name'] ?? null)
                ? trim((string) $row['escort_name'])
                : null,
            'escort_contact_number' => $hasEscort && filled($row['escort_contact_number'] ?? null)
                ? trim((string) $row['escort_contact_number'])
                : null,
            'escort_id_number' => $hasEscort && filled($row['escort_id_number'] ?? null)
                ? trim((string) $row['escort_id_number'])
                : null,
            'escort_position' => $hasEscort && filled($row['escort_position'] ?? null)
                ? trim((string) $row['escort_position'])
                : null,
            'escort_office' => $hasEscort && filled($row['escort_office'] ?? null)
                ? trim((string) $row['escort_office'])
                : null,
            'estimated_departure' => filled($row['estimated_departure'] ?? $row['dispatch_date'] ?? null)
                ? substr(str_replace(' ', 'T', (string) ($row['estimated_departure'] ?? $row['dispatch_date'])), 0, 16)
                : null,
            'estimated_arrival' => filled($row['estimated_arrival'] ?? null)
                ? substr(str_replace(' ', 'T', (string) $row['estimated_arrival']), 0, 16)
                : null,
            'allows_multi_day_run' => DispatchPlan::vehicleAllowsMultiDayRun($row),
            'mode_of_transportation' => filled($mode) ? (string) $mode : null,
            'land_transportation_source' => filled($row['land_transportation_source'] ?? null) ? trim((string) $row['land_transportation_source']) : null,
            'warehouse_released_at' => filled($row['warehouse_released_at'] ?? null)
                ? substr(str_replace(' ', 'T', (string) $row['warehouse_released_at']), 0, 16)
                : null,
            // Witness-only release attribution — do not persist Released By.
            'warehouse_released_by' => null,
            'release_witnessed_by' => filled($row['release_witnessed_by'] ?? null)
                ? trim((string) $row['release_witnessed_by'])
                : null,
            'release_witness_affiliation' => in_array($row['release_witness_affiliation'] ?? null, ['dswd', 'lgu'], true)
                ? $row['release_witness_affiliation']
                : null,
            'release_witness_contact_number' => filled($row['release_witness_contact_number'] ?? null)
                ? trim((string) $row['release_witness_contact_number'])
                : null,
            'release_witness_id_number' => filled($row['release_witness_id_number'] ?? null)
                ? trim((string) $row['release_witness_id_number'])
                : null,
            'release_witness_position' => filled($row['release_witness_position'] ?? null)
                ? trim((string) $row['release_witness_position'])
                : null,
            'release_witness_office' => filled($row['release_witness_office'] ?? null)
                ? trim((string) $row['release_witness_office'])
                : null,
            'loaded_at' => filled($row['loaded_at'] ?? null)
                ? substr(str_replace(' ', 'T', (string) $row['loaded_at']), 0, 16)
                : null,
            'loading_remarks' => filled($row['loading_remarks'] ?? null)
                ? trim((string) $row['loading_remarks'])
                : null,
            'loaded_items' => $loadedItems,
            'departed_at' => filled($row['departed_at'] ?? null)
                ? substr(str_replace(' ', 'T', (string) $row['departed_at']), 0, 16)
                : null,
            'actual_arrival' => filled($row['actual_arrival'] ?? null)
                ? substr(str_replace(' ', 'T', (string) $row['actual_arrival']), 0, 16)
                : (filled($row['delivered_at'] ?? null)
                    ? substr((string) $row['delivered_at'], 0, 10).'T12:00'
                    : null),
            // Legacy date column kept in sync from Actual Arrival for print / older consumers.
            'delivered_at' => filled($row['actual_arrival'] ?? null)
                ? substr(str_replace(' ', 'T', (string) $row['actual_arrival']), 0, 10)
                : (filled($row['delivered_at'] ?? null) ? substr((string) $row['delivered_at'], 0, 10) : null),
            'fully_delivered' => $this->yesNoLabel($row['fully_delivered'] ?? null) ?: null,
            'received_by' => filled($row['received_by'] ?? null) ? trim((string) $row['received_by']) : null,
            'received_by_id_number' => filled($row['received_by_id_number'] ?? null)
                ? trim((string) $row['received_by_id_number'])
                : (filled($row['recipient_id_number'] ?? null)
                    ? trim((string) $row['recipient_id_number'])
                    : null),
            'received_by_position' => filled($row['received_by_position'] ?? null)
                ? trim((string) $row['received_by_position'])
                : null,
            'received_by_office' => filled($row['received_by_office'] ?? null)
                ? trim((string) $row['received_by_office'])
                : null,
            'received_at' => filled($row['received_at'] ?? null)
                ? substr(str_replace(' ', 'T', (string) $row['received_at']), 0, 16)
                : null,
            'receiver_contact' => filled($row['receiver_contact'] ?? null)
                ? trim((string) $row['receiver_contact'])
                : null,
            'receipt_acknowledged' => (bool) ($row['receipt_acknowledged'] ?? false),
            'receipt_remarks' => filled($row['receipt_remarks'] ?? null)
                ? trim((string) $row['receipt_remarks'])
                : null,
        ];
    }

    /**
     * Assign the base DR to a single vehicle. For multiple vehicles, use the
     * permanent alphabetical series -A, -B ... -Z, -AA, matching vehicle order.
     *
     * @param  array<int, array<string, mixed>>  $submitted
     * @param  array<int, array<string, mixed>>  $existing
     * @return array<int, array<string, mixed>>
     */
    private function assignVehicleDrNumbers(array $submitted, array $existing, ?RequisitionIssuanceSlip $slip, int $offset = 0, bool $forceSuffix = false): array
    {
        $base = trim((string) ($slip?->dr_number ?: data_get($slip?->tracking_data, 'dr_number')));
        if ($base === '') {
            $base = 'DR-'.$slip?->id;
        }

        $rows = array_values($submitted);
        $multiple = count($rows) > 1 || $forceSuffix;

        return collect($rows)->map(function (array $row, int $index) use ($base, $multiple, $offset, $existing): array {
            $saved = $existing[$index] ?? [];
            // A DR number is reserved when this transaction is planned. Keep
            // that identity on every later save, including before release.
            if (filled($saved['dr_number'] ?? null)) {
                $row['dr_number'] = $saved['dr_number'];
                return $row;
            }
            // A new transaction receives its suffix in
            // assignDrNumbersByPlanSequence when its plan is confirmed.
            $row['dr_number'] = null;
            return $row;
        })->all();
    }

    /** A receiving-LGU source is still documented by a DR, but it has no transporter assignment. */
    private function assignLocalHandoverDrNumber(array $data, ?RequisitionIssuanceSlip $slip): array
    {
        if (! $this->hasLocalSourceAllocation($data)) {
            return $data;
        }

        $base = trim((string) ($slip?->dr_number ?: data_get($slip?->tracking_data, 'dr_number')));
        if ($base === '') {
            $base = 'DR-'.$slip?->id;
        }

        $vehicles = array_values($data['vehicle_details'] ?? []);
        $offset = (int) ($data['dr_series_offset'] ?? 0);
        $handover = is_array($data['local_handover_details'] ?? null) ? $data['local_handover_details'] : [];
        if (filled($handover['dr_number'] ?? null)) {
            $data['local_handover_details'] = $handover;
            return $data;
        }
        $localWarehouse = $this->classifiedSourceWarehouses($data)
            ->first(fn (array $warehouse): bool => ! ($warehouse['requires_transport'] ?? true));

        $handover['source_warehouse_id'] = $handover['source_warehouse_id'] ?? ($localWarehouse['id'] ?? null);
        $handover['source_warehouse_name'] = $handover['source_warehouse_name'] ?? ($localWarehouse['name'] ?? null);
        // A new local transaction receives its suffix when its plan is
        // confirmed; an already-reserved suffix was retained above.
        $handover['dr_number'] = null;
        $data['local_handover_details'] = $handover;

        return $data;
    }

    /**
     * Lock DR suffixes using the order in which transaction modes were planned.
     * Existing issued DRs remain immutable so inventory references stay valid.
     */
    private function assignDrNumbersByPlanSequence(
        array $data,
        array $existingVehicles,
        array $existingHandover,
        ?RequisitionIssuanceSlip $slip,
        array $releaseVehicleIndexes,
        bool $releaseLocal,
    ): array {
        $base = trim((string) ($slip?->dr_number ?: data_get($slip?->tracking_data, 'dr_number')));
        if ($base === '') $base = 'DR-'.$slip?->id;

        $handover = is_array($data['local_handover_details'] ?? null) ? $data['local_handover_details'] : [];
        $hasLocalTransaction = filled($handover['source_warehouse_id'] ?? null)
            || filled($handover['source_warehouse_name'] ?? null)
            || filled($handover['plan_confirmed_at'] ?? null)
            || $this->hasLocalSourceAllocation($data);
        $requiresSuffix = count($data['vehicle_details'] ?? [])
            + ($hasLocalTransaction ? 1 : 0) > 1
            || (int) ($data['dr_series_offset'] ?? 0) > 0;

        $used = collect($existingVehicles)
            ->pluck('dr_number')
            ->push($existingHandover['dr_number'] ?? null)
            ->filter()
            ->map(fn ($number): int => $this->drSuffixNumber((string) $number))
            ->filter(fn (int $number): bool => $number > 0)
            ->values();
        $vehicles = array_values($data['vehicle_details'] ?? []);

        // Rank every transaction by its immutable plan confirmation time. The
        // stable ordinal only resolves transactions confirmed in the same
        // second (for example, several vehicles confirmed together).
        $sequence = collect($vehicles)->map(fn (array $row, int $index): array => [
            'key' => "vehicle:$index",
            'planned_at' => filled($row['plan_confirmed_at'] ?? null)
                ? strtotime((string) $row['plan_confirmed_at'])
                : PHP_INT_MAX,
            'ordinal' => $index,
        ]);
        if ($hasLocalTransaction) {
            $sequence->push([
                'key' => 'local',
                'planned_at' => filled($handover['plan_confirmed_at'] ?? null)
                    ? strtotime((string) $handover['plan_confirmed_at'])
                    : PHP_INT_MAX,
                'ordinal' => count($vehicles),
            ]);
        }
        $sequence = $sequence
            ->sort(fn (array $left, array $right): int =>
                [$left['planned_at'], $left['ordinal']] <=> [$right['planned_at'], $right['ordinal']]
            )
            ->values();
        $seriesOffset = (int) ($data['dr_series_offset'] ?? 0);
        $suffixByKey = $sequence->mapWithKeys(
            fn (array $entry, int $position): array => [$entry['key'] => $seriesOffset + $position + 1],
        );

        $nextAvailableSuffix = function (string $key) use ($suffixByKey, $used): int {
            $candidate = max(1, (int) $suffixByKey->get($key, 1));
            while ($used->contains($candidate)) $candidate++;
            $used->push($candidate);
            return $candidate;
        };

        foreach ($releaseVehicleIndexes as $index) {
            if (! isset($vehicles[$index])) continue;
            $existing = $existingVehicles[$index] ?? [];
            if (filled($existing['dr_number'] ?? null)) {
                $vehicles[$index]['dr_number'] = $existing['dr_number'];
                continue;
            }
            $vehicles[$index]['dr_number'] = $requiresSuffix
                ? $base.'-'.$this->alphabeticSuffix($nextAvailableSuffix("vehicle:$index") - 1)
                : $base;
        }

        $data['vehicle_details'] = $vehicles;
        if ($releaseLocal) {
            $handover['dr_number'] = filled($existingHandover['dr_number'] ?? null)
                ? $existingHandover['dr_number']
                : ($requiresSuffix ? $base.'-'.$this->alphabeticSuffix($nextAvailableSuffix('local') - 1) : $base);
            $data['local_handover_details'] = $handover;
        }
        return $data;
    }

    private function hasLocalSourceAllocation(array $data): bool
    {
        return $this->classifiedSourceWarehouses($data)
            ->contains(fn (array $warehouse): bool => ! ($warehouse['requires_transport'] ?? true));
    }

    /** A DR belongs to an identified transport assignment, not an empty planning placeholder. */
    private function vehicleIsAssignedForDr(array $vehicle): bool
    {
        $mode = is_array($vehicle['mode_of_transportation'] ?? null)
            ? ($this->asStringList($vehicle['mode_of_transportation'])[0] ?? null)
            : ($vehicle['mode_of_transportation'] ?? null);

        return filled($vehicle['source_warehouse_id'] ?? null) || filled($vehicle['source_warehouse_name'] ?? null)
            ? filled($mode)
                && filled($vehicle['land_transportation_source'] ?? null)
                && filled($vehicle['vehicle_type'] ?? null)
                && filled($vehicle['vehicle_plate_number'] ?? null)
            : false;
    }

    private function drSuffixNumber(string $drNumber): int
    {
        if (! preg_match('/-([A-Z]+)$/', strtoupper(trim($drNumber)), $matches)) {
            return 0;
        }
        return collect(str_split($matches[1]))->reduce(
            fn (int $value, string $letter): int => ($value * 26) + (ord($letter) - 64),
            0,
        );
    }

    private function alphabeticSuffix(int $index): string
    {
        $suffix = '';
        for ($number = $index + 1; $number > 0; $number = intdiv($number - 1, 26)) {
            $suffix = chr(65 + (($number - 1) % 26)).$suffix;
        }
        return $suffix;
    }

    private function normalizeYesNo(mixed $value): ?bool
    {
        if ($value === true || $value === 1 || $value === '1' || $value === 'Yes') {
            return true;
        }
        if ($value === false || $value === 0 || $value === '0' || $value === 'No') {
            return false;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function vehicleHasDswdEscort(array $row): bool
    {
        $flag = $row['has_dswd_escort'] ?? false;

        return $flag === true
            || $flag === 1
            || $flag === '1'
            || $flag === 'Yes'
            || $flag === 'yes'
            || $flag === 'true';
    }

    private function yesNoLabel(mixed $value): string
    {
        $normalized = $this->normalizeYesNo($value);
        if ($normalized === true) {
            return 'Yes';
        }
        if ($normalized === false) {
            return 'No';
        }

        return '';
    }

    /**
     * @param  array<int, mixed>  $items
     * @return Collection<int, array{id: ?int, name: string, key: string}>
     */
    private function uniqueSourceWarehousesFromItems(array $items): Collection
    {
        return collect($items)
            ->filter(fn ($item) => is_array($item)
                && (filled($item['warehouse_id'] ?? null) || filled($item['warehouse_name'] ?? null)))
            ->map(function (array $item): array {
                $id = filled($item['warehouse_id'] ?? null) ? (int) $item['warehouse_id'] : null;
                $name = trim((string) ($item['warehouse_name'] ?? ''));
                if ($name === '' && $id !== null) {
                    $name = "Warehouse #{$id}";
                }

                return [
                    'id' => $id,
                    'name' => $name,
                    'key' => $id !== null ? 'id:'.$id : 'name:'.strtolower($name),
                ];
            })
            ->unique('key')
            ->values();
    }

    /**
     * Auto-assign source warehouse when the plan has exactly one unique remote warehouse.
     * Receiving-LGU warehouses are never auto-assigned onto vehicles.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applySourceWarehouseDefaults(array $data): array
    {
        $classified = $this->classifiedSourceWarehouses($data);
        $remote = $classified
            ->filter(fn (array $warehouse) => ($warehouse['requires_transport'] ?? true))
            ->values();
        if ($remote->count() !== 1 || ! isset($data['vehicle_details']) || ! is_array($data['vehicle_details'])) {
            return $data;
        }

        $sole = $remote->first();
        $data['vehicle_details'] = collect($data['vehicle_details'])
            ->map(function ($row) use ($sole) {
                if (! is_array($row)) {
                    return $row;
                }
                if (blank($row['source_warehouse_id'] ?? null) && blank($row['source_warehouse_name'] ?? null)) {
                    $row['source_warehouse_id'] = $sole['id'];
                    $row['source_warehouse_name'] = $sole['name'];
                } elseif (blank($row['source_warehouse_name'] ?? null) && filled($row['source_warehouse_id'] ?? null)) {
                    $row['source_warehouse_name'] = $sole['name'];
                } elseif (blank($row['source_warehouse_id'] ?? null) && filled($row['source_warehouse_name'] ?? null)) {
                    $row['source_warehouse_id'] = $sole['id'];
                }

                return $row;
            })
            ->all();

        return $data;
    }

    /**
     * Drop leftover / seeded blank vehicles when every allocation is receiving-LGU local stock.
     * Intentional vehicles assigned to a receiving-LGU warehouse are left for assertNoReceivingLguVehicles.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function pruneLocalOnlyTransportVehicles(array $data): array
    {
        $classified = $this->classifiedSourceWarehouses($data);
        if ($classified->isEmpty()) {
            return $data;
        }

        $remote = $classified->first(fn (array $warehouse) => ($warehouse['requires_transport'] ?? true));
        if ($remote !== null) {
            return $data;
        }

        $rows = collect($data['vehicle_details'] ?? [])
            ->filter(fn ($row) => is_array($row))
            ->values();

        $hasReceivingLguAssignment = $rows->contains(
            fn (array $row) => $this->vehicleSourceRequiresNoTransport($row, $data, $classified)
        );
        if ($hasReceivingLguAssignment) {
            return $data;
        }

        // Local-only plan with blank / unassigned leftover seed rows — clear transport.
        $data['vehicle_details'] = [];
        $data['number_of_vehicles'] = 0;
        $data['driver'] = null;
        $data['driver_contact_number'] = null;
        $data['vehicle_plate_number'] = null;
        $data['mode_of_transportation'] = null;
        $data['vehicle_types'] = null;
        $data['dispatch_date'] = null;
        $data['estimated_arrival'] = null;
        $data['departed_at'] = null;

        return $data;
    }

    /**
     * Reject vehicles whose source is already at the recipient custody location.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertNoReceivingLguVehicles(array $data): void
    {
        $classified = $this->classifiedSourceWarehouses($data);
        $errors = [];

        foreach (array_values($data['vehicle_details'] ?? []) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            if ($this->vehicleSourceRequiresNoTransport($row, $data, $classified)) {
                $errors["vehicle_details.$index.source_warehouse_id"] =
                    'Vehicle delivery planning is not allowed for stock already at the recipient custody location. The recipient handles any onward distribution.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Collection<int, array{id: ?int, name: string, key: string, classification: array{kind: string, label: string}, requires_transport: bool}>
     */
    private function classifiedSourceWarehouses(array $data): Collection
    {
        $context = $this->receivingContextFromData($data);
        $unique = $this->uniqueSourceWarehousesFromItems($data['items'] ?? []);
        $ids = $unique->pluck('id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $catalog = $ids->isEmpty()
            ? collect()
            : Warehouse::query()->whereIn('id', $ids)->get()->keyBy('id');

        return $unique->map(function (array $warehouse) use ($catalog, $context): array {
            $model = filled($warehouse['id'] ?? null) ? $catalog->get((int) $warehouse['id']) : null;
            $meta = [
                'id' => $warehouse['id'] ?? null,
                'name' => $warehouse['name'] ?? ($model?->name ?? ''),
                'display_name' => $model?->display_name ?? ($warehouse['name'] ?? ''),
                'warehouse_type' => $model?->warehouse_type ?? '',
                'ownership' => $model?->ownership ?? '',
                'municipality' => $model?->municipality ?? '',
                'province' => $model?->province ?? '',
                'category' => $model?->category ?? '',
                'partnership' => $model?->partnership ?? '',
            ];
            $classification = $this->classifySourceWarehouse($meta, $context);

            return [
                ...$warehouse,
                'warehouse_type' => $meta['warehouse_type'],
                'ownership' => $meta['ownership'],
                'municipality' => $meta['municipality'],
                'province' => $meta['province'],
                'partnership' => $meta['partnership'],
                'name' => $meta['name'] !== '' ? $meta['name'] : ($warehouse['name'] ?? ''),
                'classification' => $classification,
                'requires_transport' => ($classification['kind'] ?? '') !== 'receiving_lgu',
            ];
        })->values();
    }

    /**
     * True when every transport-required vehicle already has warehouse_released_at.
     *
     * @param  array<string, mixed>  $data
     */
    private function allTransportVehiclesReleased(array $data): bool
    {
        $classified = $this->classifiedSourceWarehouses($data);
        $rows = collect($data['vehicle_details'] ?? [])->filter(fn ($row) => is_array($row))->values();
        if ($rows->isEmpty()) {
            return false;
        }

        $transportRows = $rows->filter(
            fn (array $row): bool => ! $this->vehicleSourceRequiresNoTransport($row, $data, $classified)
        );

        if ($transportRows->isEmpty()) {
            return false;
        }

        return $transportRows->every(fn (array $row): bool => filled($row['warehouse_released_at'] ?? null));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{lgu: string, municipality: string, province: string, requesting_agency: string, receiving_agency_lgu: string, recipient: string}
     */
    private function receivingContextFromData(array $data): array
    {
        $requestId = $data['request_id'] ?? null;
        $request = filled($requestId)
            ? AssistanceRequest::query()->with('requisitionIssuanceSlip:id,request_id,recipient')->find($requestId)
            : null;

        return [
            'lgu' => (string) ($request?->lgu ?? ''),
            'municipality' => (string) ($request?->municipality ?? ''),
            'province' => (string) ($request?->province ?? ''),
            'requesting_agency' => (string) ($request?->requesting_agency ?? ''),
            'receiving_agency_lgu' => (string) ($data['receiving_agency_lgu'] ?? ''),
            'recipient' => (string) ($request?->requisitionIssuanceSlip?->recipient ?? ''),
        ];
    }

    /**
     * Mirror of Dispatches.jsx classifySourceWarehouse.
     *
     * @param  array<string, mixed>  $warehouse
     * @param  array<string, string>  $receivingContext
     * @return array{kind: string, label: string}
     */
    private function classifySourceWarehouse(array $warehouse, array $receivingContext): array
    {
        $ownership = $this->normalizePlaceToken($warehouse['ownership'] ?? '');
        $warehouseType = $this->normalizePlaceToken($warehouse['warehouse_type'] ?? '');
        $partnership = $this->normalizePlaceToken($warehouse['partnership'] ?? '');
        $nameToken = $this->normalizePlaceToken($warehouse['name'] ?? ($warehouse['display_name'] ?? ''));
        $municipalityToken = $this->normalizePlaceToken($warehouse['municipality'] ?? '');

        $receivingTokens = collect([
            $receivingContext['lgu'] ?? '',
            $receivingContext['municipality'] ?? '',
            $receivingContext['requesting_agency'] ?? '',
            $receivingContext['receiving_agency_lgu'] ?? '',
            $receivingContext['recipient'] ?? '',
        ])
            ->map(fn ($value) => $this->normalizePlaceToken($value))
            ->filter()
            ->values();

        $matchesReceiving = $receivingTokens->contains(
            fn (string $token) => $this->tokensOverlap($token, $municipalityToken)
                || $this->tokensOverlap($token, $nameToken)
        );

        $isRegionalOrDswd = str_contains($warehouseType, 'regional')
            || str_contains($warehouseType, 'satellite')
            || $ownership === 'owned'
            || $ownership === 'rented'
            || str_contains($nameToken, 'dswd')
            || str_contains($nameToken, 'regional');

        $isPartnerNga = $ownership === 'nga'
            || in_array($partnership, ['ppa', 'dpwh'], true);

        // DSWD regional / satellite sources always need transport planning, even when
        // the warehouse municipality string overlaps the recipient locality.
        if ($isRegionalOrDswd) {
            return ['kind' => 'dswd_regional', 'label' => 'DSWD / Regional warehouse'];
        }

        if ($isPartnerNga) {
            return ['kind' => 'partner_nga', 'label' => 'Partner / NGA warehouse'];
        }

        // A source already held at the recipient's registered custody location
        // is a direct handover for any recipient type, not only an LGU.
        if ($matchesReceiving) {
            return ['kind' => 'receiving_lgu', 'label' => 'Recipient custody warehouse'];
        }

        if ($ownership === 'lgu' || str_contains($warehouseType, 'preposition')) {
            return ['kind' => 'other_lgu', 'label' => 'Other LGU warehouse'];
        }

        return ['kind' => 'other', 'label' => 'Other warehouse'];
    }

    private function normalizePlaceToken(mixed $value): string
    {
        $text = strtolower(trim((string) $value));
        $text = preg_replace('/\b(city|municipality|lgu|province|of)\b/u', ' ', $text) ?? $text;
        $text = preg_replace('/[^a-z0-9]+/u', '', $text) ?? $text;

        return trim($text);
    }

    private function tokensOverlap(string $left, string $right): bool
    {
        if ($left === '' || $right === '') {
            return false;
        }

        return $left === $right
            || str_contains($left, $right)
            || str_contains($right, $left);
    }

    /**
     * @param  array<string, mixed>  $vehicle
     * @param  array<string, mixed>  $data
     * @param  Collection<int, array{id: ?int, name: string, classification?: array{kind: string}, requires_transport?: bool}>  $classified
     */
    private function vehicleSourceRequiresNoTransport(array $vehicle, array $data, Collection $classified): bool
    {
        $explicitSources = collect($vehicle['source_warehouses'] ?? [])
            ->filter(fn ($source) => is_array($source) && (filled($source['id'] ?? null) || filled($source['name'] ?? null)))
            ->values()->all();
        foreach ($explicitSources as $source) {
            $candidate = [
                ...$vehicle,
                'source_warehouse_id' => $source['id'] ?? null,
                'source_warehouse_name' => $source['name'] ?? null,
            ];
            unset($candidate['source_warehouses']);
            if ($this->vehicleSourceRequiresNoTransport($candidate, $data, $classified)) return true;
        }
        if ($explicitSources !== []) return false;
        $hasWarehouse = filled($vehicle['source_warehouse_id'] ?? null)
            || filled($vehicle['source_warehouse_name'] ?? null);
        if (! $hasWarehouse) {
            return false;
        }

        $match = $classified->first(function (array $warehouse) use ($vehicle): bool {
            return $this->warehouseKeysMatch(
                $vehicle['source_warehouse_id'] ?? null,
                $vehicle['source_warehouse_name'] ?? null,
                $warehouse['id'] ?? null,
                $warehouse['name'] ?? null,
            );
        });

        if ($match !== null) {
            return ! ($match['requires_transport'] ?? true);
        }

        // Vehicle points at a warehouse not on allocation lines — classify from catalog + context.
        $context = $this->receivingContextFromData($data);
        $model = filled($vehicle['source_warehouse_id'] ?? null)
            ? Warehouse::query()->find((int) $vehicle['source_warehouse_id'])
            : null;
        $meta = [
            'id' => $model?->id,
            'name' => $model?->name ?? trim((string) ($vehicle['source_warehouse_name'] ?? '')),
            'display_name' => $model?->display_name ?? '',
            'warehouse_type' => $model?->warehouse_type ?? '',
            'ownership' => $model?->ownership ?? '',
            'municipality' => $model?->municipality ?? '',
            'province' => $model?->province ?? '',
            'category' => $model?->category ?? '',
            'partnership' => $model?->partnership ?? '',
        ];
        $classification = $this->classifySourceWarehouse($meta, $context);

        return ($classification['kind'] ?? '') === 'receiving_lgu';
    }

    /**
     * @param  array<string, mixed>  $vehicle
     * @param  Collection<int, array{id: ?int, name: string, key: string}>  $warehouses
     */
    private function vehicleMatchesSourceWarehouse(array $vehicle, Collection $warehouses): bool
    {
        return collect($this->vehicleSourceWarehouses($vehicle))->contains(function (array $source) use ($warehouses): bool {
            return $warehouses->contains(function (array $warehouse) use ($source): bool {
            return $this->warehouseKeysMatch(
                $source['id'] ?? null,
                $source['name'] ?? null,
                $warehouse['id'] ?? null,
                $warehouse['name'] ?? null,
            );
            });
        });
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $vehicle
     */
    private function itemMatchesVehicleWarehouse(array $item, array $vehicle): bool
    {
        return collect($this->vehicleSourceWarehouses($vehicle))->contains(fn (array $source): bool =>
            $this->warehouseKeysMatch(
                $item['warehouse_id'] ?? null,
                $item['warehouse_name'] ?? null,
                $source['id'] ?? null,
                $source['name'] ?? null,
            ));
    }

    private function notifyDeliveryUpdateStakeholders(User $actor, DispatchPlan $dispatch, DispatchDeliveryUpdate $update): void
    {
        $dispatch->loadMissing('request.encoder', 'request.lguSubmitter');
        $request = $dispatch->request;
        $stage = (string) $update->stage;
        $stageLabel = Str::headline($stage);
        $vehicle = $dispatch->resolvedVehicleDetails()[(int) $update->vehicle_index] ?? [];
        $vehicleLabel = trim((string) ($vehicle['vehicle_plate_number'] ?? $vehicle['vehicle_type'] ?? 'Vehicle '.((int) $update->vehicle_index + 1)));
        $reference = $dispatch->dispatch_number ?: 'Dispatch #'.$dispatch->id;
        $message = "{$vehicleLabel} — {$stageLabel}. {$update->message}";
        $basePayload = [
            'workflow' => 'Transport delivery monitoring',
            'action_key' => 'dispatch_delivery_update_'.$stage,
            'action_required' => in_array($stage, ['delay', 'incident'], true),
            'title' => "{$reference}: {$stageLabel}",
            'message' => Str::limit($message, 500),
            'request_id' => $dispatch->request_id,
            'reference_number' => $request?->reference_number,
            'url' => route('delivery-monitoring.index', ['dispatch_id' => $dispatch->id, 'update_id' => $update->id]),
            'meta' => [
                'dispatch_id' => $dispatch->id,
                'delivery_update_id' => $update->id,
                'vehicle_index' => (int) $update->vehicle_index,
                'stage' => $stage,
                'location' => $update->location,
                'photo_count' => count($update->photo_paths ?? []),
            ],
        ];

        // Dispatch/RROS personnel monitor every field update.
        $operational = User::query()->where('is_active', true)
            ->where(function ($query): void {
                $query->permission('manage dispatches')
                    ->orWhereHas('roles', fn ($roles) => $roles->whereIn('name', ['RROS', 'RROS AA', 'Super Admin']));
            })->get();

        // DRMD operations receives milestone and exception updates. Executives
        // receive departure/completion notices plus exception escalations, not
        // routine checkpoint traffic.
        $drmdStages = ['departed', 'arrived', 'unloading_completed', 'delay', 'incident'];
        $drmd = in_array($stage, $drmdStages, true)
            ? User::query()->where('is_active', true)->whereHas('roles', fn ($roles) => $roles->whereIn('name', ['DRMD AA', 'DRMD Chief', 'DRRS', 'DRRS AA', 'DRIMS', 'QRT', 'Quick Response Team']))->get()
            : collect();
        $leadershipStages = ['departed', 'unloading_completed', 'delay', 'incident'];
        $leadership = in_array($stage, $leadershipStages, true)
            ? User::query()->where('is_active', true)->whereHas('roles', fn ($roles) => $roles->whereIn('name', ['Regional Director', 'RD', 'Assistant Regional Director', 'ARD', 'Assistant Regional Director for Operations', 'ARDO']))->get()
            : collect();

        collect($operational)->merge($drmd)->merge($leadership)
            ->filter(fn (User $user): bool => (int) $user->id !== (int) $actor->id)
            ->unique('id')
            ->each(fn (User $user) => $user->notify(new WorkflowNotification($basePayload)));

        // Notify the concerned LGU/recipient accounts that are actually linked
        // to the originating request. External recipients without a DROMIS
        // account remain covered by the recorded contact details.
        if ($request) {
            $recipientUsers = collect([$request->encoder, $request->lguSubmitter]);
            if (filled($request->lgu_psgc_code)) {
                $recipientUsers = $recipientUsers->merge(
                    User::query()->where('is_active', true)
                        ->where('lgu_psgc_code', $request->lgu_psgc_code)
                        ->whereHas('roles', fn ($roles) => $roles->where('name', 'LGU'))
                        ->get()
                );
            }
            $recipientPayload = [
                ...$basePayload,
                'url' => route('lgu.dromic-requests.index'),
                'action_required' => in_array($stage, ['arrived', 'unloading_completed', 'delay', 'incident'], true),
            ];
            $recipientUsers->filter(fn (?User $user): bool => $user && $user->is_active && (int) $user->id !== (int) $actor->id)
                ->unique('id')
                ->each(fn (User $user) => $user->notify(new WorkflowNotification($recipientPayload)));
        }
    }

    /** @return array<int, array{id: mixed, name: mixed}> */
    private function vehicleSourceWarehouses(array $vehicle): array
    {
        $sources = collect($vehicle['source_warehouses'] ?? [])
            ->filter(fn ($source) => is_array($source) && (filled($source['id'] ?? null) || filled($source['name'] ?? null)))
            ->map(fn (array $source): array => ['id' => $source['id'] ?? null, 'name' => $source['name'] ?? null])
            ->values()->all();
        if ($sources !== []) return $sources;
        if (filled($vehicle['source_warehouse_id'] ?? null) || filled($vehicle['source_warehouse_name'] ?? null)) {
            return [['id' => $vehicle['source_warehouse_id'] ?? null, 'name' => $vehicle['source_warehouse_name'] ?? null]];
        }
        return [];
    }

    private function warehouseKeysMatch(
        mixed $leftId,
        mixed $leftName,
        mixed $rightId,
        mixed $rightName,
    ): bool {
        if (filled($leftId) && filled($rightId)) {
            return (int) $leftId === (int) $rightId;
        }

        $left = strtolower(trim((string) ($leftName ?? '')));
        $right = strtolower(trim((string) ($rightName ?? '')));

        return $left !== '' && $right !== '' && $left === $right;
    }

    /**
     * Enrich Received By library options with LGU directory position/office/contact when metadata is empty.
     *
     * @return list<array{id:int,value:string,label:string,metadata:array<string,mixed>,position?:string|null,office?:string|null,contact_number?:string|null,id_number?:string|null}>
     */
    private function receivedByCatalogWithLguMetadata(): array
    {
        $entries = OperationalLibraryValue::catalogEntries('dispatch_received_by');
        $profiles = $this->lguOfficialProfilesByNameKey();

        return array_map(function (array $entry) use ($profiles): array {
            $metadata = is_array($entry['metadata'] ?? null) ? $entry['metadata'] : [];
            $key = $this->normalizePersonNameKey((string) ($entry['value'] ?? ''));
            $profile = $key !== '' ? ($profiles[$key] ?? null) : null;

            if (is_array($profile)) {
                foreach (['position', 'office', 'contact_number', 'id_number'] as $field) {
                    if (! filled($metadata[$field] ?? null) && filled($profile[$field] ?? null)) {
                        $metadata[$field] = $profile[$field];
                    }
                }
            }

            $entry['metadata'] = $metadata;
            $entry['position'] = filled($metadata['position'] ?? null) ? (string) $metadata['position'] : null;
            $entry['office'] = filled($metadata['office'] ?? null) ? (string) $metadata['office'] : null;
            $entry['contact_number'] = filled($metadata['contact_number'] ?? null)
                ? (string) $metadata['contact_number']
                : null;
            $entry['id_number'] = filled($metadata['id_number'] ?? null) ? (string) $metadata['id_number'] : null;

            return $entry;
        }, $entries);
    }

    /**
     * @return array{position:?string,office:?string,contact_number:?string,id_number:?string}
     */
    private function resolveReceivingRepresentativeProfile(
        ?string $name,
        ?AssistanceRequest $request = null,
        ?string $recipientFallback = null,
    ): array {
        $empty = [
            'position' => null,
            'office' => null,
            'contact_number' => null,
            'id_number' => null,
        ];

        $name = preg_replace('/\s+/u', ' ', trim((string) $name)) ?? '';
        if ($name === '') {
            return $empty;
        }

        $key = $this->normalizePersonNameKey($name);
        if ($key === '') {
            return $empty;
        }

        $directory = $this->resolveLguDirectoryForRequest($request);
        if ($directory) {
            $directory->loadMissing(['officials', 'lswdoAlternates', 'ldrrmoOfficers', 'staffMembers']);
            $scoped = $this->matchOfficialProfileInDirectory($directory, $key, $recipientFallback);
            if ($scoped !== null) {
                return $scoped;
            }
        }

        $profiles = $this->lguOfficialProfilesByNameKey();

        return $profiles[$key] ?? $empty;
    }

    /**
     * @return array{position:?string,office:?string,contact_number:?string,id_number:?string}|null
     */
    private function matchOfficialProfileInDirectory(
        LguDirectoryEntry $directory,
        string $nameKey,
        ?string $recipientFallback = null,
    ): ?array {
        $office = $this->formatDirectoryOfficeLabel($directory, $recipientFallback);
        $primaryContact = filled($directory->lswd_contact_number)
            ? trim((string) $directory->lswd_contact_number)
            : (filled($directory->lswd_alternate_contact_number)
                ? trim((string) $directory->lswd_alternate_contact_number)
                : null);

        foreach ($directory->officials as $official) {
            /** @var LguDirectoryOfficial $official */
            $display = trim((string) ($official->override_name ?: $official->name));
            if ($display === '' || $this->normalizePersonNameKey($display) !== $nameKey) {
                continue;
            }

            $position = trim((string) (
                $official->override_position_designation
                ?: $official->position_designation
                ?: ''
            ));

            return [
                'position' => $position !== '' ? $position : null,
                'office' => $office !== '' ? $office : null,
                'contact_number' => in_array($official->role, ['lswd_officer', 'lswd_officer_alternate'], true)
                    ? $primaryContact
                    : null,
                'id_number' => filled($official->id_number) ? trim((string) $official->id_number) : null,
            ];
        }

        foreach ($directory->lswdoAlternates as $alternate) {
            /** @var LguDirectoryLswdoAlternate $alternate */
            $display = trim((string) ($alternate->name ?? ''));
            if ($display === '' || $this->normalizePersonNameKey($display) !== $nameKey) {
                continue;
            }

            $position = trim((string) ($alternate->position ?? ''));
            $contact = trim((string) ($alternate->contact_number ?? ''));

            return [
                'position' => $position !== '' ? $position : null,
                'office' => $office !== '' ? $office : null,
                'contact_number' => $contact !== '' ? $contact : $primaryContact,
                'id_number' => filled($alternate->id_number) ? trim((string) $alternate->id_number) : null,
            ];
        }

        $legacyAlternate = trim((string) ($directory->lswd_alternate_name ?? ''));
        if ($legacyAlternate !== '' && $this->normalizePersonNameKey($legacyAlternate) === $nameKey) {
            $position = trim((string) ($directory->lswd_alternate_position ?? ''));

            return [
                'position' => $position !== '' ? $position : null,
                'office' => $office !== '' ? $office : null,
                'contact_number' => filled($directory->lswd_alternate_contact_number)
                    ? trim((string) $directory->lswd_alternate_contact_number)
                    : $primaryContact,
                'id_number' => null,
            ];
        }

        $directory->loadMissing(['ldrrmoOfficers', 'staffMembers']);

        foreach ($directory->ldrrmoOfficers as $officer) {
            $display = trim((string) ($officer->name ?? ''));
            if ($display === '' || $this->normalizePersonNameKey($display) !== $nameKey) {
                continue;
            }

            $position = trim((string) ($officer->designation ?? ''));
            $contact = collect([
                $officer->mobile_number,
                $officer->hotline_number,
                $officer->landline_number,
            ])->map(fn ($value) => trim((string) $value))->filter()->implode(' / ');

            return [
                'position' => $position !== '' ? $position : null,
                'office' => filled($officer->office) ? trim((string) $officer->office) : ($office !== '' ? $office : null),
                'contact_number' => $contact !== '' ? $contact : null,
                'id_number' => filled($officer->id_number) ? trim((string) $officer->id_number) : null,
            ];
        }

        foreach ($directory->staffMembers as $member) {
            $display = trim((string) ($member->name ?? ''));
            if ($display === '' || $this->normalizePersonNameKey($display) !== $nameKey) {
                continue;
            }

            $position = trim((string) ($member->position ?? ''));
            $contact = trim((string) ($member->contact_number ?? ''));
            $memberOffice = trim((string) ($member->office ?? ''));

            return [
                'position' => $position !== '' ? $position : null,
                'office' => $memberOffice !== '' ? $memberOffice : ($office !== '' ? $office : null),
                'contact_number' => $contact !== '' ? $contact : null,
                'id_number' => filled($member->id_number) ? trim((string) $member->id_number) : null,
            ];
        }

        return null;
    }

    private function resolveLguDirectoryForRequest(?AssistanceRequest $request): ?LguDirectoryEntry
    {
        if (! $request) {
            return null;
        }

        if (filled($request->lgu_psgc_code)) {
            $byPsgc = LguDirectoryEntry::query()
                ->where('psgc_code', $request->lgu_psgc_code)
                ->where('is_active', true)
                ->first();
            if ($byPsgc) {
                return $byPsgc;
            }
        }

        foreach ([$request->municipality, $request->lgu, $request->requesting_agency] as $place) {
            $place = trim((string) $place);
            if ($place === '') {
                continue;
            }

            $match = LguDirectoryEntry::query()
                ->where('is_active', true)
                ->where(function ($query) use ($place): void {
                    $query->where('lgu_name', 'like', "%{$place}%")
                        ->orWhere('override_lgu_name', 'like', "%{$place}%");
                })
                ->first();
            if ($match) {
                return $match;
            }
        }

        return null;
    }

    /**
     * @var array<string, array{position:?string,office:?string,contact_number:?string,id_number:?string}>|null
     */
    private ?array $lguOfficialProfileCache = null;

    /**
     * @return array<string, array{position:?string,office:?string,contact_number:?string,id_number:?string}>
     */
    private function lguOfficialProfilesByNameKey(): array
    {
        if (is_array($this->lguOfficialProfileCache)) {
            return $this->lguOfficialProfileCache;
        }

        $profiles = [];
        $entries = LguDirectoryEntry::query()
            ->where('is_active', true)
            ->with(['officials', 'lswdoAlternates', 'ldrrmoOfficers', 'staffMembers'])
            ->get([
                'id',
                'lgu_name',
                'override_lgu_name',
                'lgu_level',
                'lswd_contact_number',
                'lswd_alternate_contact_number',
                'lswd_alternate_name',
                'lswd_alternate_position',
            ]);

        foreach ($entries as $entry) {
            $office = $this->formatDirectoryOfficeLabel($entry);
            $primaryContact = filled($entry->lswd_contact_number)
                ? trim((string) $entry->lswd_contact_number)
                : (filled($entry->lswd_alternate_contact_number)
                    ? trim((string) $entry->lswd_alternate_contact_number)
                    : null);

            foreach ($entry->officials as $official) {
                $display = trim((string) ($official->override_name ?: $official->name));
                $key = $this->normalizePersonNameKey($display);
                if ($key === '' || isset($profiles[$key])) {
                    continue;
                }

                $position = trim((string) (
                    $official->override_position_designation
                    ?: $official->position_designation
                    ?: ''
                ));

                $profiles[$key] = [
                    'position' => $position !== '' ? $position : null,
                    'office' => $office !== '' ? $office : null,
                    'contact_number' => in_array($official->role, ['lswd_officer', 'lswd_officer_alternate'], true)
                        ? $primaryContact
                        : null,
                    'id_number' => filled($official->id_number) ? trim((string) $official->id_number) : null,
                ];
            }

            foreach ($entry->lswdoAlternates as $alternate) {
                $display = trim((string) ($alternate->name ?? ''));
                $key = $this->normalizePersonNameKey($display);
                if ($key === '' || isset($profiles[$key])) {
                    continue;
                }

                $position = trim((string) ($alternate->position ?? ''));
                $contact = trim((string) ($alternate->contact_number ?? ''));

                $profiles[$key] = [
                    'position' => $position !== '' ? $position : null,
                    'office' => $office !== '' ? $office : null,
                    'contact_number' => $contact !== '' ? $contact : $primaryContact,
                    'id_number' => filled($alternate->id_number) ? trim((string) $alternate->id_number) : null,
                ];
            }

            foreach ($entry->ldrrmoOfficers as $officer) {
                $display = trim((string) ($officer->name ?? ''));
                $key = $this->normalizePersonNameKey($display);
                if ($key === '' || isset($profiles[$key])) {
                    continue;
                }

                $position = trim((string) ($officer->designation ?? ''));
                $contact = collect([
                    $officer->mobile_number,
                    $officer->hotline_number,
                    $officer->landline_number,
                ])->map(fn ($value) => trim((string) $value))->filter()->implode(' / ');
                $officerOffice = trim((string) ($officer->office ?? ''));

                $profiles[$key] = [
                    'position' => $position !== '' ? $position : null,
                    'office' => $officerOffice !== '' ? $officerOffice : ($office !== '' ? $office : null),
                    'contact_number' => $contact !== '' ? $contact : null,
                    'id_number' => filled($officer->id_number) ? trim((string) $officer->id_number) : null,
                ];
            }

            foreach ($entry->staffMembers as $member) {
                $display = trim((string) ($member->name ?? ''));
                $key = $this->normalizePersonNameKey($display);
                if ($key === '' || isset($profiles[$key])) {
                    continue;
                }

                $position = trim((string) ($member->position ?? ''));
                $contact = trim((string) ($member->contact_number ?? ''));
                $memberOffice = trim((string) ($member->office ?? ''));

                $profiles[$key] = [
                    'position' => $position !== '' ? $position : null,
                    'office' => $memberOffice !== '' ? $memberOffice : ($office !== '' ? $office : null),
                    'contact_number' => $contact !== '' ? $contact : null,
                    'id_number' => filled($member->id_number) ? trim((string) $member->id_number) : null,
                ];
            }

            $legacyAlternate = trim((string) ($entry->lswd_alternate_name ?? ''));
            $legacyKey = $this->normalizePersonNameKey($legacyAlternate);
            if ($legacyKey !== '' && ! isset($profiles[$legacyKey])) {
                $position = trim((string) ($entry->lswd_alternate_position ?? ''));
                $profiles[$legacyKey] = [
                    'position' => $position !== '' ? $position : null,
                    'office' => $office !== '' ? $office : null,
                    'contact_number' => filled($entry->lswd_alternate_contact_number)
                        ? trim((string) $entry->lswd_alternate_contact_number)
                        : $primaryContact,
                    'id_number' => null,
                ];
            }
        }

        $this->lguOfficialProfileCache = $profiles;

        return $this->lguOfficialProfileCache;
    }

    /**
     * @return list<array{id:int,value:string,label:string,metadata:array<string,mixed>,position?:string|null,office?:string|null,contact_number?:string|null,id_number?:string|null}>
     */
    private function driverCatalogWithMetadata(): array
    {
        return array_map(function (array $entry): array {
            $metadata = is_array($entry['metadata'] ?? null) ? $entry['metadata'] : [];
            $entry['metadata'] = $metadata;
            $entry['position'] = filled($metadata['position'] ?? null) ? (string) $metadata['position'] : null;
            $entry['office'] = filled($metadata['office'] ?? null) ? (string) $metadata['office'] : null;
            $entry['contact_number'] = filled($metadata['contact_number'] ?? null)
                ? (string) $metadata['contact_number']
                : null;
            $entry['id_number'] = filled($metadata['id_number'] ?? null) ? (string) $metadata['id_number'] : null;

            return $entry;
        }, OperationalLibraryValue::catalogEntries('dispatch_driver'));
    }

    private function formatDirectoryOfficeLabel(
        LguDirectoryEntry $entry,
        ?string $recipientFallback = null,
    ): string {
        $fallback = preg_replace('/\s+/u', ' ', trim((string) $recipientFallback)) ?? '';
        if ($fallback !== '') {
            return $fallback;
        }

        $name = preg_replace(
            '/\s+/u',
            ' ',
            trim((string) ($entry->override_lgu_name ?: $entry->lgu_name)),
        ) ?? '';
        $level = trim((string) ($entry->lgu_level ?? ''));

        if ($level !== '' && $name !== '') {
            return "{$level} - {$name}";
        }

        return $name;
    }

    private function normalizePersonNameKey(mixed $value): string
    {
        $text = mb_strtolower(trim((string) $value));
        if ($text === '') {
            return '';
        }

        // Drop common honorifics / professional suffixes so RIS vs directory spellings still match.
        $text = preg_replace(
            '/\b(ms\.?|mr\.?|mrs\.?|miss|atty\.?|hon\.?|eng\.?|dr\.?)\b/u',
            ' ',
            $text,
        ) ?? $text;
        $text = preg_replace(
            '/,\s*(rsw|rsv|rn|md|phd|cpa|lld|jd)\b.*$/iu',
            '',
            $text,
        ) ?? $text;
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
