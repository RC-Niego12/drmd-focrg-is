<?php

namespace App\Http\Controllers;

use App\Models\RisSyncRun;
use App\Services\RisSheetSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RisSyncController extends Controller
{
    public function store(Request $request, RisSheetSyncService $sync): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()?->hasAnyRole(['RROS', 'RROS AA', 'Super Admin']), 403);
        try {
            $result = $sync->run('manual', $request->user()->id);
            $message = "RIS/DR sync completed: {$result['records_created']} created, {$result['records_updated']} updated, {$result['items_synced']} FNI rows synced.";

            return $request->expectsJson()
                ? response()->json(['message' => $message, 'data' => $result])
                : back()->with('success', $message);
        } catch (\Throwable $exception) {
            report($exception);
            $message = $exception->getMessage() ?: 'RIS/DR synchronization failed. No data was changed.';

            return $request->expectsJson()
                ? response()->json(['message' => $message], 503)
                : back()->with('error', $message);
        }
    }

    public function history(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasAnyRole(['RROS', 'RROS AA', 'Super Admin']), 403);

        return response()->json(['data' => RisSyncRun::latest('started_at')->limit(20)->get()]);
    }
}
