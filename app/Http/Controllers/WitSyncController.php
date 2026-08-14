<?php

namespace App\Http\Controllers;

use App\Services\WitSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WitSyncController extends Controller
{
    public function store(Request $request, WitSyncService $sync): RedirectResponse
    {
        abort_unless($request->user()?->hasAnyRole(['RROS', 'RROS AA', 'Super Admin']) || $request->user()?->can('manage inventory'), 403);
        try {
            $result = $sync->run('manual', $request->user()->id);

            return back()->with('success', $result['changed'] ? 'WIT sync completed and changes were applied.' : 'WIT sync completed; no data changes were found.');
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }
    }

    public function history(Request $request, WitSyncService $sync): JsonResponse
    {
        abort_unless(
            $request->user()?->hasAnyRole(['RROS', 'RROS AA', 'Super Admin'])
            || $request->user()?->can('manage inventory')
            || $request->user()?->can('view dashboards'),
            403
        );

        return response()->json(['data' => $sync->history()->map(fn ($log) => [
            'id' => $log->id,
            'event' => $log->event,
            'trigger' => $log->new_values['trigger'] ?? null,
            'changed' => $log->new_values['changed'] ?? false,
            'before' => $log->old_values,
            'after' => $log->new_values['state'] ?? null,
            'stages' => $log->new_values['stages'] ?? null,
            'message' => $log->new_values['message'] ?? null,
            'user' => $log->user?->name ?? 'System',
            'created_at' => $log->created_at?->toIso8601String(),
        ])->values()]);
    }
}
