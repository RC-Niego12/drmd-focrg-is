<?php

namespace App\Http\Controllers;

use App\Services\AccessNotificationCenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NotificationController extends Controller
{
    public function __construct(private readonly AccessNotificationCenter $notificationCenter) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->notificationCenter->forUser($request->user()));
    }

    public function read(Request $request, string $notification): Response
    {
        $item = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $item->markAsRead();

        return response()->noContent();
    }
}
