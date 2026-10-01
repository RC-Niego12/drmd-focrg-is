<?php

namespace App\Support;

use App\Models\AssistanceRequest;

final class LguDromicReportTitle
{
    public static function make(AssistanceRequest $report): string
    {
        $payload = (array) ($report->lgu_dromic_payload ?? []);
        if ((bool) data_get($payload, 'standalone_relief_request')) {
            $incidentType = trim((string) data_get($payload, 'incident_type', 'Disaster Incident'));
            $linkedCount = count((array) data_get($payload, 'linked_incident_series_keys', []));
            $scope = $linkedCount > 1
                ? "covering {$linkedCount} {$incidentType} incidents"
                : "for {$incidentType}";

            return "LGU Consolidated Relief Augmentation Request {$scope}";
        }

        $number = $report->lgu_dromic_report_number ?: data_get($payload, 'report_number', 1);
        $classification = $report->lgu_dromic_report_classification ?: data_get($payload, 'report_classification', 'regular');
        $label = match ($classification) {
            'first_and_final' => 'First and Final DROMIC / Situational Report',
            'terminal' => 'Terminal DROMIC / Situational Report',
            default => "DROMIC / Situational Report No. {$number}",
        };
        $barangays = collect(data_get($payload, 'affected_barangays', []))
            ->map(fn ($name): string => trim((string) $name))
            ->filter()
            ->unique()
            ->values();
        $incidentType = trim((string) data_get($payload, 'incident_type', $report->incident?->name ?? 'Disaster Incident'));
        $province = trim((string) ($report->province ?: data_get($payload, 'province')));
        $municipality = trim((string) ($report->municipality ?: data_get($payload, 'municipality')));
        if ($province !== '') {
            $municipality = trim((string) preg_replace('/,\s*'.preg_quote($province, '/').'\s*$/i', '', $municipality));
        }
        $locations = collect();
        if ($barangays->count() <= 2) {
            $locations->push($barangays
                ->map(fn ($name): string => 'Brgy. '.preg_replace('/^(?:brgy\.?|barangay)\s+/i', '', $name))
                ->implode(' and '));
        }
        $locations->push($municipality)->push($province);
        $incidentTitle = $incidentType;
        if ($locations->filter()->isNotEmpty()) {
            $incidentTitle .= ' in '.$locations->filter()->implode(', ');
        }

        return "LGU {$label} on the {$incidentTitle}";
    }
}
