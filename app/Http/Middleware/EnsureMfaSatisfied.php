<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMfaSatisfied
{
    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) config('services.cc_idp.bypass_mfa', true)) {
            $request->session()->forget('mfa_required');

            return $next($request);
        }

        if (! $request->session()->get('mfa_required')) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if (! $user->mfa_enabled) {
            return redirect()->route('mfa.setup');
        }

        return redirect()->route('mfa.verify');
    }
}
