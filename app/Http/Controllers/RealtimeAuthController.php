<?php

namespace App\Http\Controllers;

use App\Services\RealtimePublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RealtimeAuthController extends Controller
{
    public function __invoke(Request $request, RealtimePublisher $realtime): JsonResponse
    {
        abort_unless($realtime->enabled(), 503, 'Real-time service is unavailable.');

        return response()->json([
            'token' => $realtime->connectionToken($request->user()),
        ])->header('Cache-Control', 'no-store, private');
    }
}
