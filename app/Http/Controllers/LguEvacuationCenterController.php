<?php

namespace App\Http\Controllers;

use App\Services\EvacuationCenterInventoryService;
use App\Services\LguWarehousePersonnelSyncService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LguEvacuationCenterController extends Controller
{
    public function index(
        Request $request,
        EvacuationCenterInventoryService $inventory,
        LguWarehousePersonnelSyncService $warehouseScope,
    ): Response {
        $user = $request->user();
        abort_unless(
            $user && ($user->hasRole('LGU') || $user->can('submit lgu dromic requests')),
            403,
            'You are not authorized to view LGU evacuation centers.',
        );

        $directory = $warehouseScope->directoryForUser($user);
        abort_unless($directory, 403, 'Your account is not linked to an LGU directory.');

        $inventoryPayload = $inventory->inventory();
        $centers = $inventory->sortCenters($inventory->centersForUser($user));
        $featured = $inventory->featuredDashboardForUser($user);
        $lguName = $directory->override_lgu_name ?: $directory->lgu_name;
        $metrics = $inventory->metrics($centers);
        $photoSheets = collect(config('evac_center_photo_sheets', []));
        $matchedPhotoSheet = $photoSheets->first(
            fn (array $sheet) => strcasecmp((string) ($sheet['sheet_municipality'] ?? ''), (string) ($featured['sheet_municipality'] ?? $lguName)) === 0
        );
        $geotagSheetUrl = is_array($matchedPhotoSheet) ? ($matchedPhotoSheet['source_url'] ?? null) : null;

        return Inertia::render('EvacuationCenters/Index', [
            'workspace' => 'lgu',
            'title' => 'Evacuation Centers',
            'scopeLabel' => $lguName,
            'lgu' => [
                'name' => $lguName,
                'level' => $directory->lgu_level,
                'psgc_code' => $directory->psgc_code,
            ],
            'metrics' => $metrics,
            'centers' => $centers->values()->all(),
            'municipalities' => [],
            'filters' => [
                'municipality' => '',
                'search' => trim((string) $request->query('search', '')),
            ],
            'featuredDashboards' => $featured ? [[
                'code' => $featured['code'] ?? '',
                'name' => $featured['name'] ?? $lguName,
                'province' => $featured['province'] ?? '',
                'title' => $featured['title'] ?? ('Geotagged ECs — '.$lguName),
                'looker_url' => $featured['looker_url'] ?? '',
                'open_url' => $featured['open_url'] ?? str_replace('/embed/reporting/', '/reporting/', (string) ($featured['looker_url'] ?? '')),
                'sheet_municipality' => $featured['sheet_municipality'] ?? $lguName,
                'geotag_sheet_url' => $geotagSheetUrl ?: '',
                'count' => $centers->count(),
                'photo_count' => (int) ($metrics['with_photos'] ?? 0),
            ]] : [],
            'activeFeatured' => $featured ? [
                ...$featured,
                'geotag_sheet_url' => $geotagSheetUrl ?: '',
                'count' => $centers->count(),
                'photo_count' => (int) ($metrics['with_photos'] ?? 0),
            ] : null,
            'sourceUrl' => $inventoryPayload['source'],
            'generatedAt' => $inventoryPayload['generated_at'],
            'photoSources' => $inventoryPayload['photo_sources'] ?? [],
            'photosMergedAt' => $inventoryPayload['photos_merged_at'] ?? null,
            'filterBasePath' => '/lgu/evacuation-centers',
            'temporaryNotice' => 'LGU evacuation centers from the Caraga inventory, aligned with your geotag sheet when available.',
        ]);
    }
}
