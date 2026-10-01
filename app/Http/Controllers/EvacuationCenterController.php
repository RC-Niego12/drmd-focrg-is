<?php

namespace App\Http\Controllers;

use App\Services\EvacuationCenterInventoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EvacuationCenterController extends Controller
{
    public function index(Request $request, EvacuationCenterInventoryService $inventory): Response
    {
        $user = $request->user();
        abort_unless(
            $user && ($user->hasRole('DRIMS') || $user->hasRole('Super Admin') || $user->can('manage dromic reports')),
            403,
            'You are not authorized to view evacuation centers.',
        );

        $inventoryPayload = $inventory->inventory();
        $all = collect($inventoryPayload['centers']);
        $municipalities = $all
            ->map(fn (array $row) => [
                'value' => (string) ($row['municipality'] ?? ''),
                'label' => trim(($row['municipality'] ?? '').' · '.($row['province'] ?? '')),
                'province' => (string) ($row['province'] ?? ''),
            ])
            ->filter(fn (array $row) => $row['value'] !== '')
            ->unique('value')
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        $selectedMunicipality = trim((string) $request->query('municipality', ''));
        $featured = $inventory->featuredDashboards($all);
        $featuredNames = collect($featured)->pluck('sheet_municipality')->filter()->all();

        if ($selectedMunicipality === '' || strcasecmp($selectedMunicipality, 'all') === 0) {
            $centers = $all->values();
            $scopeLabel = 'All Caraga inventory';
            $selectedMunicipality = '';
        } elseif (strcasecmp($selectedMunicipality, 'featured') === 0) {
            $centers = $all->filter(
                fn (array $row) => in_array((string) ($row['municipality'] ?? ''), $featuredNames, true)
            )->values();
            $scopeLabel = 'Featured LGUs (Mainit, Alegria, Sison)';
        } else {
            $centers = $inventory->centersForMunicipality($selectedMunicipality);
            $scopeLabel = $selectedMunicipality;
        }

        $centers = $inventory->sortCenters($centers);
        $metrics = $inventory->metrics($centers);
        $activeFeatured = collect($featured)->first(
            fn (array $dashboard) => strcasecmp((string) ($dashboard['sheet_municipality'] ?? ''), $selectedMunicipality) === 0
        );

        return Inertia::render('EvacuationCenters/Index', [
            'workspace' => 'drims',
            'title' => 'Evacuation Centers',
            'scopeLabel' => $scopeLabel,
            'lgu' => null,
            'metrics' => $metrics,
            'centers' => $centers->values()->all(),
            'municipalities' => $municipalities,
            'filters' => [
                'municipality' => $selectedMunicipality === '' ? '' : $selectedMunicipality,
                'search' => trim((string) $request->query('search', '')),
            ],
            'featuredDashboards' => $featured,
            'activeFeatured' => $activeFeatured,
            'sourceUrl' => $inventoryPayload['source'],
            'generatedAt' => $inventoryPayload['generated_at'],
            'photoSources' => $inventoryPayload['photo_sources'] ?? [],
            'photosMergedAt' => $inventoryPayload['photos_merged_at'] ?? null,
            'filterBasePath' => '/evacuation-centers',
            'temporaryNotice' => 'Full Caraga EC inventory. Featured geotag coverage (Mainit, Alegria, Sison) matches inventory EC count to photos — use the LGU chips to zoom in.',
        ]);
    }
}
