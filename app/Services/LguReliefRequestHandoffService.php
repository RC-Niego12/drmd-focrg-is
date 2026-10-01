<?php

namespace App\Services;

use App\Models\AssistanceRequest;
use App\Models\FniLibraryItem;
use App\Models\RequestParty;
use App\Support\AffectedAreaList;
use App\Support\IncidentOccurrenceDisplay;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LguReliefRequestHandoffService
{
    public function handoff(AssistanceRequest $report, ?int $encodedBy = null): AssistanceRequest
    {
        return DB::transaction(function () use ($report, $encodedBy): AssistanceRequest {
            $sourceIds = $this->revisionFamilyIds($report);
            $payload = (array) $report->lgu_dromic_payload;
            $receivedAt = collect([
                $report->lgu_signed_report_uploaded_at,
                $report->lgu_signed_request_uploaded_at,
                $report->lgu_submitted_to_dswd_at,
            ])->filter()->sortDesc()->first() ?? now();
            $operational = AssistanceRequest::query()
                ->whereIn('source_lgu_dromic_request_id', $sourceIds)
                ->first();
            $incidentType = trim((string) data_get($payload, 'incident_type', ''));
            $incidentName = trim((string) data_get($payload, 'incident_name', ''));
            $incidentSpecificDetails = trim((string) data_get($payload, 'incident_specific_details', ''));
            $isStandalone = (bool) data_get($payload, 'standalone_relief_request');
            $linkedIncidents = collect(data_get($payload, 'linked_incidents', []))
                ->filter(fn ($row): bool => is_array($row))
                ->values();
            $assessmentIncidents = $this->assessmentIncidentsFromLinked($linkedIncidents, $incidentType, $report);
            $affectedAreas = collect(data_get($payload, 'affected_barangays', []))
                ->merge($linkedIncidents->flatMap(fn (array $row): array => array_values((array) ($row['affected_barangays'] ?? []))))
                ->map(fn ($value): string => trim((string) $value))
                ->filter()
                ->unique()
                ->values()
                ->all();
            $areaDetails = AffectedAreaList::format($affectedAreas);
            if ($areaDetails !== '') {
                $incidentSpecificDetails = $areaDetails;
            }
            $occurrenceDates = $linkedIncidents
                ->map(fn (array $row): string => Str::substr((string) ($row['occurrence_started_at'] ?? ''), 0, 10))
                ->filter(fn (string $value): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value))
                ->unique()
                ->sort()
                ->values();
            $occurrenceStart = $occurrenceDates->first()
                ?: Str::substr((string) (data_get($payload, 'occurrence_started_at') ?: data_get($payload, 'incident_date') ?: ''), 0, 10);
            $occurrenceEnd = $occurrenceDates->last() ?: $occurrenceStart;
            $affectedPersons = max(
                (int) data_get($payload, 'affected_persons', 0),
                (int) $linkedIncidents->sum(fn (array $row): int => (int) ($row['affected_persons'] ?? 0)),
                (int) collect((array) data_get($payload, 'area_rows', []))
                    ->sum(fn ($row): int => (int) data_get($row, 'affected_persons', 0)),
            );
            $requestPartyId = RequestParty::query()
                ->where('is_active', true)
                ->whereHas('lguDirectoryEntry', fn ($query) => $query->where('psgc_code', $report->lgu_psgc_code))
                ->value('id');

            if (
                $areaDetails === ''
                && (
                    Str::lower($incidentSpecificDetails) === Str::lower($incidentType)
                    || Str::lower($incidentSpecificDetails) === Str::lower($incidentName)
                )
            ) {
                $incidentSpecificDetails = '';
            }

            $attributes = [
                'incident_id' => $report->incident_id,
                'encoded_by' => $encodedBy ?: $report->lgu_relief_reviewed_by,
                'lgu_submitted_by' => $report->lgu_submitted_by,
                'request_party_id' => $requestPartyId ?: $operational?->request_party_id,
                'requesting_agency' => $report->requesting_agency,
                'lgu' => $report->lgu,
                'lgu_level' => $report->lgu_level,
                'lgu_psgc_code' => $report->lgu_psgc_code,
                'province' => $report->province,
                'municipality' => $report->municipality,
                'barangay' => $report->barangay,
                'requester' => $report->requester,
                'requester_position' => $report->requester_position,
                'requester_address' => $report->requester_address,
                'contact_number' => $report->contact_number,
                'date_requested' => $report->lgu_signed_request_uploaded_at?->toDateString() ?? now()->toDateString(),
                'date_received_by_drmd' => $receivedAt->toDateString(),
                'purpose' => 'Relief Augmentation',
                'assessment_summary' => data_get($payload, 'incident_summary')
                    ?: data_get($payload, 'narrative')
                    ?: $this->itemSummary($payload),
                'incident_details' => $incidentSpecificDetails,
                'incident_count' => max(1, $assessmentIncidents !== [] ? count($assessmentIncidents) : 1),
                'assessment_form_data' => [
                    'request_type' => 'Disaster',
                    'response_purpose' => 'Relief Augmentation',
                    'requested_fni_items' => data_get($payload, 'requested_fni_items', []),
                    'affected_persons' => $affectedPersons > 0 ? $affectedPersons : '',
                    'affected_areas' => $affectedAreas,
                    'information_source' => $report->requesting_agency ?: $report->lgu ?: $report->municipality,
                    'information_date' => $receivedAt->toDateString(),
                    'incident_type' => data_get($payload, 'incident_type'),
                    'incident_specific_details' => $incidentSpecificDetails,
                    'occurrence_started_at' => $occurrenceStart ?: (data_get($payload, 'occurrence_started_at') ?: data_get($payload, 'incident_date')),
                    'occurrence_ended_span' => $occurrenceEnd,
                    'incident_occurrence_display' => IncidentOccurrenceDisplay::format($occurrenceStart, $occurrenceEnd),
                    'incident_status' => data_get($payload, 'incident_status'),
                    'incident_ended_at' => data_get($payload, 'incident_ended_at'),
                    'identified_needs' => data_get($payload, 'needs'),
                    'lgu_report_remarks' => data_get($payload, 'remarks'),
                    'standalone_relief_request' => $isStandalone,
                    'request_mode' => data_get($payload, 'request_mode'),
                    'linked_incident_series_keys' => array_values((array) data_get($payload, 'linked_incident_series_keys', [])),
                    'linked_incidents' => $linkedIncidents->all(),
                    ...(
                        $assessmentIncidents !== []
                            ? ['incidents' => $assessmentIncidents]
                            : []
                    ),
                    'source_lgu_snapshot' => [
                        'report_reference' => $report->reference_number,
                        'request_reference' => $report->lgu_relief_request_reference,
                        'standalone_relief_request' => $isStandalone,
                        'affected_families' => (int) data_get($payload, 'affected_families', 0),
                        'affected_persons' => $affectedPersons,
                        'affected_barangays' => $affectedAreas,
                        'requested_fni_items' => data_get($payload, 'requested_fni_items', []),
                        'linked_incidents' => $linkedIncidents->all(),
                    ],
                    'source_lgu_dromic_reference' => $report->reference_number,
                    'source_lgu_request_reference' => $report->lgu_relief_request_reference,
                    'source_incident_code' => 'DIS-INC-'.Str::upper(Str::substr($report->lgu_dromic_series_key ?: 'REQ-'.$report->id, 0, 12)),
                ],
                'affected_families' => $report->affected_families
                    ?? ($linkedIncidents->sum(fn (array $row): int => (int) ($row['affected_families'] ?? 0)) ?: null),
                'endorsed_to_drrs' => true,
                'date_endorsed_to_drrs' => now()->toDateString(),
                'status' => $operational?->status ?: 'endorsed',
                'submitted_at' => $operational?->submitted_at ?: now(),
            ];

            // Keep the LGU relief request reference as the FNI request code.
            // Do not mint a second REQ- number for the same attached letter/report.
            $preferredReference = trim((string) ($report->lgu_relief_request_reference ?: ''));

            if ($operational) {
                $operational->update([
                    ...$attributes,
                    'source_lgu_dromic_request_id' => $report->id,
                    ...($preferredReference !== '' && $operational->reference_number !== $preferredReference
                        ? ['reference_number' => $this->uniqueOperationalReference($preferredReference, $operational->id)]
                        : []),
                ]);
            } else {
                $operational = AssistanceRequest::query()->create([
                    ...$attributes,
                    'reference_number' => $preferredReference !== ''
                        ? $this->uniqueOperationalReference($preferredReference)
                        : 'REQ-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
                    'submission_type' => 'fni_request',
                    'source_lgu_dromic_request_id' => $report->id,
                ]);
            }

            $this->syncItems($operational, $payload);
            $report->update(['lgu_routing_status' => 'routed_to_drrs']);

            return $operational->fresh(['items', 'sourceLguDromicReport']);
        });
    }

    private function assessmentIncidentsFromLinked(Collection $linkedIncidents, string $incidentType, AssistanceRequest $report): array
    {
        if ($linkedIncidents->isEmpty()) {
            return [];
        }

        return $linkedIncidents
            ->map(function (array $incident) use ($incidentType, $report): array {
                $barangays = array_values(array_filter((array) ($incident['affected_barangays'] ?? [])));

                return [
                    'incident_type' => trim((string) ($incident['incident_type'] ?? $incidentType)) ?: $incidentType,
                    'incident_details' => trim((string) ($incident['incident_name'] ?? '')),
                    'occurrence_at' => Str::substr((string) ($incident['occurrence_started_at'] ?? ''), 0, 16),
                    'city_municipality' => $incident['municipality'] ?? $report->municipality,
                    'barangay' => $barangays !== [] ? implode(', ', $barangays) : ($incident['barangay'] ?? ''),
                    'affected_families' => (int) ($incident['affected_families'] ?? 0),
                    'affected_persons' => (int) ($incident['affected_persons'] ?? 0),
                    'description' => trim(
                        ($incident['incident_name'] ?? '').
                        (filled($incident['incident_code'] ?? null) ? ' ('.$incident['incident_code'].')' : '')
                    ),
                    'source_reference' => $incident['incident_code'] ?? $report->reference_number,
                    'series_key' => $incident['series_key'] ?? null,
                ];
            })
            ->all();
    }

    private function uniqueOperationalReference(string $preferred, ?int $ignoreId = null): string
    {
        $candidate = $preferred;
        $suffix = 2;

        while (
            AssistanceRequest::query()
                ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
                ->where('reference_number', $candidate)
                ->exists()
        ) {
            $candidate = $preferred.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function revisionFamilyIds(AssistanceRequest $report): array
    {
        $ids = collect([$report->id]);
        $cursor = $report;
        while ($cursor->lgu_correction_of_id) {
            $ids->push((int) $cursor->lgu_correction_of_id);
            $cursor = AssistanceRequest::query()->find($cursor->lgu_correction_of_id);
            if (! $cursor) {
                break;
            }
        }

        return AssistanceRequest::query()
            ->whereIn('id', $ids)
            ->orWhereIn('lgu_correction_of_id', $ids)
            ->pluck('id')
            ->merge($ids)
            ->unique()
            ->values()
            ->all();
    }

    private function itemSummary(array $payload): string
    {
        $summary = collect(data_get($payload, 'requested_fni_items', []))
            ->map(fn (array $item): string => trim(
                data_get($item, 'item_name', 'FNI item').': '.
                data_get($item, 'requested_quantity', 0).' '.
                data_get($item, 'unit_of_measure', '')
            ))
            ->filter()
            ->join('; ');

        return $summary !== ''
            ? 'LGU-requested FNI: '.$summary
            : 'LGU relief augmentation request received for DRRS assessment.';
    }

    private function syncItems(AssistanceRequest $request, array $payload): void
    {
        $rows = collect(data_get($payload, 'requested_fni_items', []));
        $library = FniLibraryItem::query()
            ->whereIn('id', $rows->pluck('fni_library_item_id')->filter())
            ->get()
            ->keyBy('id');
        $selectedIds = [];

        foreach ($rows as $row) {
            $item = $library->get((int) data_get($row, 'fni_library_item_id'));
            if (! $item) {
                continue;
            }
            $selectedIds[] = $item->id;
            $request->items()->updateOrCreate(
                ['fni_library_item_id' => $item->id],
                [
                    'item_name' => trim($item->item_name.($item->brand_description ? ' - '.$item->brand_description : '')),
                    'requested_quantity' => (int) round((float) data_get($row, 'requested_quantity')),
                    'unit' => $item->unit_of_measure ?: 'item',
                    'priority' => 'normal',
                    'status' => 'pending',
                ],
            );
        }

        $request->items()
            ->whereNotNull('fni_library_item_id')
            ->when($selectedIds !== [], fn ($query) => $query->whereNotIn('fni_library_item_id', $selectedIds))
            ->delete();
    }
}
