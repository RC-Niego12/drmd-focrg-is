<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLguCredentialsReviewed
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->must_change_password) {
            return $next($request);
        }

        if ($request->routeIs(
            'lgu.credentials.edit',
            'lgu.credentials.update',
            'lgu.credentials.retain',
            'logout',
            'session.keepalive',
        )) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Please review your LGU login credentials before continuing.',
                'redirect' => route('lgu.credentials.edit'),
            ], 409);
        }

        return redirect()->route('lgu.credentials.edit');
    }
}
