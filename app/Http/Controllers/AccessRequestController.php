<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AccessNotificationCenter;
use App\Notifications\AccessRequestedNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Validation\Rule;

class AccessRequestController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccessNotificationCenter $notificationCenter,
    ) {}

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
                'response_message' => $user->access_response_message,
                'decided_at' => $user->access_decided_at?->toDateTimeString(),
            ],
            'roleOptions' => $this->notificationCenter->roleOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'requested_role' => ['required', 'string', Rule::in(AccessNotificationCenter::REQUESTABLE_ROLES)],
        ]);

        $user = $request->user();
        $old = $user->only(['access_status', 'requested_role', 'access_requested_at']);

        $user->update([
            'access_status' => $user->access_status === 'approved' ? 'approved' : 'pending',
            'requested_role' => $validated['requested_role'],
            'access_requested_at' => now(),
            'access_response_message' => null,
            'access_decided_at' => null,
        ]);

        $this->audit->log('access.requested', $user, $old, $user->only(['access_status', 'requested_role', 'access_requested_at']), $user->id);

        User::role('Super Admin')
            ->where('is_active', true)
            ->get()
            ->each(fn (User $admin) => $admin->notify(new AccessRequestedNotification($user->fresh())));

        return back()->with('success', 'Access request submitted. Please wait for the Super Admin to grant your user level.');
    }
}
