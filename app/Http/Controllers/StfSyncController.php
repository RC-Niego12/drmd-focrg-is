<?php

namespace App\Http\Controllers;

use App\Models\StfSyncRun;
use App\Services\StfDocumentPdfService;
use App\Services\StfSheetSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StfSyncController extends Controller
{
    public function store(Request $request, StfSheetSyncService $sync): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()?->hasAnyRole(['RROS', 'RROS AA', 'Super Admin']), 403);

        try {
            $result = $sync->run('manual', $request->user()->id);
            $source = $result['source'] ?? 'operational';
            $message = "STF sync completed ({$source}): {$result['rows_seen']} row(s) reviewed, {$result['items_synced']} tracking row(s) refreshed.";

            return $request->expectsJson()
                ? response()->json(['message' => $message, 'data' => $result])
                : back()->with('success', $message);
        } catch (\Throwable $exception) {
            report($exception);
            $message = $exception->getMessage() ?: 'STF synchronization failed. No data was changed.';

            return $request->expectsJson()
                ? response()->json(['message' => $message], 503)
                : back()->with('error', $message);
        }
    }

    public function history(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasAnyRole(['RROS', 'RROS AA', 'Super Admin']), 403);

        return response()->json(['data' => StfSyncRun::latest('started_at')->limit(20)->get()]);
    }

    public function index(Request $request, StfSheetSyncService $sync): JsonResponse
    {
        abort_unless($request->user()?->hasAnyRole(['RROS', 'RROS AA', 'Super Admin']), 403);

        return response()->json([
            'data' => $sync->trackingRows(),
            'latest_sync' => StfSyncRun::latest('started_at')->first(),
        ]);
    }

    public function previewPdf(Request $request, StfDocumentPdfService $pdf)
    {
        abort_unless($request->user()?->hasAnyRole(['RROS', 'RROS AA', 'Super Admin']), 403);
        $reference = trim((string) $request->query('reference'));
        abort_if($reference === '', 404);

        return $pdf->stream($reference, true);
    }
}
