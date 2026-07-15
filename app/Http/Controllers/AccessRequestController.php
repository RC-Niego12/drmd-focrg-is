<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class AccessRequestController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Access/Request', [
            'access' => [
                'status' => $user->access_status,
                'requested_role' => $user->requested_role,
                'requested_at' => $user->access_requested_at?->toDateTimeString(),
                'approved_at' => $user->access_approved_at?->toDateTimeString(),
                'assigned_role' => $user->getRoleNames()->first(),
                'home_url' => route('dashboard'),
            ],
            'roleOptions' => Role::query()
                ->whereIn('name', ['RROS', 'DRRS', 'DRIMS', 'DRMD AA', 'DRMD Financial Analyst'])
                ->orderByRaw("case name when 'RROS' then 0 when 'DRRS' then 1 when 'DRIMS' then 2 when 'DRMD AA' then 3 when 'DRMD Financial Analyst' then 4 else 5 end")
                ->pluck('name')
                ->map(fn (string $role): array => ['value' => $role, 'label' => $role])
                ->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'requested_role' => ['required', 'string', 'exists:roles,name'],
        ]);

        $user = $request->user();
        $old = $user->only(['access_status', 'requested_role', 'access_requested_at']);

        $user->update([
            'access_status' => $user->access_status === 'approved' ? 'approved' : 'pending',
            'requested_role' => $validated['requested_role'],
            'access_requested_at' => now(),
        ]);

        $this->audit->log('access.requested', $user, $old, $user->only(['access_status', 'requested_role', 'access_requested_at']), $user->id);

        return back()->with('success', 'Access request submitted. Please wait for the Super Admin to grant your user level.');
    }
}
