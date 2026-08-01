<?php

namespace App\Services;

use App\Models\AssistanceRequest;
use Carbon\Carbon;
use Illuminate\Support\Collection;

final class PreviousAugmentationResolver
{
    /**
     * Build previous-augmentation rows for the same LGU and disaster incident (type + date).
     *
     * @return array{has_previous: bool, rows: list<array{unit: string, description: string, quantity: float|int|string, remarks: string}>}
     */
    public function resolve(AssistanceRequest $current): array
    {
        $current->loadMissing(['incident', 'items']);

        $incidentName = trim((string) (
            $current->incident?->name
            ?: data_get($current->assessment_form_data, 'incident_type')
            ?: data_get($current->assessment_form_data, 'incident_name')
            ?: ''
        ));
        $incidentDate = $this->normalizeDate(
            $current->incident?->incident_date
            ?? data_get($current->assessment_form_data, 'occurrence_started_at')
            ?? data_get($current->assessment_form_data, 'incident_date')
        );

        if ($incidentName === '' || $incidentDate === null) {
            return ['has_previous' => false, 'rows' => $this->blankRows()];
        }

        $hasLguIdentity = filled($current->lgu_psgc_code)
            || filled($current->requesting_agency)
            || (filled($current->province) && filled($current->municipality));

        if (! $hasLguIdentity) {
            return ['has_previous' => false, 'rows' => $this->blankRows()];
        }

        $priors = AssistanceRequest::query()
            ->with(['items', 'incident'])
            ->whereKeyNot($current->id)
            ->where('submission_type', '!=', 'lgu_dromic_relief_request')
            ->whereNotNull('assessment_status')
            ->where(function ($query): void {
                $query->whereIn('assessment_status', ['draft', 'final', 'submitted'])
                    ->orWhereIn('status', ['under_review', 'acted', 'approved', 'partially_approved']);
            })
            ->where(function ($query) use ($current): void {
                if (filled($current->lgu_psgc_code)) {
                    $query->where('lgu_psgc_code', $current->lgu_psgc_code);
                }
                if (filled($current->requesting_agency)) {
                    $query->orWhere('requesting_agency', $current->requesting_agency);
                }
                if (filled($current->province) && filled($current->municipality)) {
                    $query->orWhere(function ($location) use ($current): void {
                        $location->where('province', $current->province)
                            ->where('municipality', $current->municipality);
                    });
                }
            })
            ->whereHas('incident', function ($incident) use ($incidentName, $incidentDate): void {
                $incident->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($incidentName)])
                    ->whereDate('incident_date', $incidentDate->toDateString());
            })
            ->latest('updated_at')
            ->limit(20)
            ->get();

        $rows = $priors
            ->flatMap(fn (AssistanceRequest $prior) => $this->rowsFromPrior($prior))
            ->values()
            ->take(12)
            ->all();

        if ($rows === []) {
            return ['has_previous' => false, 'rows' => $this->blankRows()];
        }

        return [
            'has_previous' => true,
            'rows' => array_pad($rows, max(3, count($rows)), [
                'unit' => '',
                'description' => '',
                'quantity' => '',
                'remarks' => '',
            ]),
        ];
    }

    /**
     * @return Collection<int, array{unit: string, description: string, quantity: float|int|string, remarks: string}>
     */
    private function rowsFromPrior(AssistanceRequest $prior): Collection
    {
        $monthYear = $this->augmentationMonthYear($prior);

        return $prior->items
            ->filter(fn ($item): bool => filled($item->item_name) && (float) $item->requested_quantity > 0)
            ->map(fn ($item): array => [
                'unit' => trim((string) ($item->unit ?: '')),
                'description' => trim((string) $item->item_name),
                'quantity' => is_numeric($item->requested_quantity)
                    ? (floor((float) $item->requested_quantity) == (float) $item->requested_quantity
                        ? (int) $item->requested_quantity
                        : round((float) $item->requested_quantity, 2))
                    : $item->requested_quantity,
                'remarks' => $monthYear,
            ])
            ->values();
    }

    private function augmentationMonthYear(AssistanceRequest $prior): string
    {
        $raw = data_get($prior->assessment_form_data, 'assessment_date')
            ?: data_get($prior->assessment_form_data, 'prepared_at')
            ?: $prior->date_endorsed_to_drrs
            ?: $prior->updated_at
            ?: $prior->created_at;

        $date = $this->normalizeDate($raw);

        return $date ? $date->format('F Y') : '';
    }

    private function normalizeDate(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy()->startOfDay();
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->startOfDay();
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        try {
            return Carbon::parse($text)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<array{unit: string, description: string, quantity: string, remarks: string}>
     */
    private function blankRows(): array
    {
        return array_fill(0, 3, [
            'unit' => '',
            'description' => '',
            'quantity' => '',
            'remarks' => '',
        ]);
    }
}
