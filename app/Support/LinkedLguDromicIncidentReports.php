<?php

namespace App\Support;

use App\Models\AssistanceRequest;
use Illuminate\Support\Str;

final class LinkedLguDromicIncidentReports
{
    /**
     * Latest revision of every SitRep number for incidents covered by a relief request letter.
     *
     * @return list<array<string, mixed>>
     */
    public static function for(AssistanceRequest $report): array
    {
        $payload = (array) ($report->lgu_dromic_payload ?? []);
        $standalone = (bool) data_get($payload, 'standalone_relief_request');
        $linkedMeta = collect(data_get($payload, 'linked_incidents', []))
            ->values();
        $seriesKeys = collect(data_get($payload, 'linked_incident_series_keys', []))
            ->filter()
            ->values();
        if ($seriesKeys->isEmpty()) {
            $seriesKeys = $linkedMeta->pluck('series_key')->filter()->values();
        }

        // Lump / consolidated request letters are identified by linked incident keys even if
        // the standalone flag is missing from a partial model load.
        $isLumpRequest = $standalone || $seriesKeys->isNotEmpty();

        // Non-lump request letters are tied to one incident series — still surface every SitRep number.
        if (! $isLumpRequest) {
            if (filled($report->lgu_dromic_series_key)) {
                $seriesKeys = collect([$report->lgu_dromic_series_key]);
            } elseif ($seriesKeys->isEmpty()) {
                return [];
            }
        }

        if ($seriesKeys->isEmpty()) {
            return [];
        }

        $seriesOrder = $seriesKeys->values()->all();
        $metaBySeries = $linkedMeta->keyBy('series_key');

        return AssistanceRequest::query()
            ->with('incident:id,name')
            ->where('submission_type', 'lgu_dromic_relief_request')
            ->whereIn('lgu_dromic_series_key', $seriesKeys->all())
            ->where(function ($query): void {
                $query->whereNull('lgu_dromic_payload->standalone_relief_request')
                    ->orWhere('lgu_dromic_payload->standalone_relief_request', false)
                    ->orWhere('lgu_dromic_payload->standalone_relief_request', 0)
                    ->orWhere('lgu_dromic_payload->standalone_relief_request', 'false');
            })
            ->whereIn('lgu_report_status', ['final', 'advance_submitted', 'submitted'])
            ->where(fn ($query) => $query->whereNull('lgu_correction_target')->orWhere('lgu_correction_target', 'report'))
            ->get()
            ->groupBy(fn (AssistanceRequest $row): string => ($row->lgu_dromic_series_key ?: 'request-'.$row->id).'|'.($row->lgu_dromic_report_number ?? 'draft-'.$row->id))
            ->map(function ($versions) use ($metaBySeries): array {
                $latest = $versions->sortByDesc(fn (AssistanceRequest $version): array => [
                    (int) $version->lgu_dromic_revision_number,
                    $version->updated_at?->timestamp ?? 0,
                    $version->id,
                ])->first();
                $meta = $metaBySeries->get($latest->lgu_dromic_series_key, []);
                $affectedBarangays = array_values(array_filter((array) (
                    data_get($meta, 'affected_barangays')
                    ?: data_get($latest->lgu_dromic_payload, 'affected_barangays', [])
                )));
                $areaLabel = count($affectedBarangays) > 0
                    ? implode(', ', $affectedBarangays)
                    : (data_get($meta, 'municipality') ?: $latest->municipality ?: 'Affected area');
                $reportNumber = (int) ($latest->lgu_dromic_report_number ?: 1);
                $incidentCode = data_get($meta, 'incident_code')
                    ?: ('DIS-INC-'.Str::upper(Str::substr($latest->lgu_dromic_series_key ?: 'REQ-'.$latest->id, 0, 12)));

                $affectedPersons = (int) (
                    data_get($latest->lgu_dromic_payload, 'affected_persons')
                    ?: collect((array) data_get($latest->lgu_dromic_payload, 'area_rows', []))
                        ->sum(fn ($area): int => (int) data_get($area, 'affected_persons', 0))
                );

                return [
                    'id' => $latest->id,
                    'reference_number' => $latest->reference_number,
                    'report_title' => LguDromicReportTitle::make($latest),
                    'report_number' => $reportNumber,
                    'incident_name' => data_get($meta, 'incident_name')
                        ?: data_get($latest->lgu_dromic_payload, 'incident_name')
                        ?: $latest->incident?->name
                        ?: 'Linked incident',
                    'incident_code' => $incidentCode,
                    'affected_barangays' => $affectedBarangays,
                    'affected_families' => (int) ($latest->affected_families ?? data_get($latest->lgu_dromic_payload, 'affected_families', 0)),
                    'affected_persons' => $affectedPersons,
                    'occurrence_started_at' => data_get($latest->lgu_dromic_payload, 'occurrence_started_at')
                        ?: $latest->incident?->incident_date?->toDateString(),
                    'area_label' => $areaLabel,
                    'tab_label' => 'No. '.$reportNumber.' · '.$areaLabel,
                    'series_key' => $latest->lgu_dromic_series_key,
                    'lgu_report_status' => $latest->lgu_report_status,
                    'lgu_signed_report_path' => $latest->lgu_signed_report_path,
                    'lgu_signed_report_name' => $latest->lgu_signed_report_name,
                    'has_advance_copy' => true,
                    'has_signed_copy' => filled($latest->lgu_signed_report_path),
                ];
            })
            ->sortBy([
                fn (array $row): int => array_search($row['series_key'], $seriesOrder, true) === false
                    ? PHP_INT_MAX
                    : (int) array_search($row['series_key'], $seriesOrder, true),
                fn (array $row): int => (int) $row['report_number'],
                fn (array $row): int => (int) $row['id'],
            ])
            ->values()
            ->all();
    }
}
