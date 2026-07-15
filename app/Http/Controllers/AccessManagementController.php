<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class AccessManagementController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'status', 'role']);

        $users = User::query()
            ->with('roles:id,name')
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('access_status', $filters['status']))
            ->when(filled($filters['role'] ?? null), fn ($query) => $query->role($filters['role']))
            ->when(filled($filters['search'] ?? null), function ($query) use ($filters): void {
                $needle = (string) $filters['search'];

                $query->where(function ($builder) use ($needle): void {
                    $builder
                        ->where('name', 'like', "%{$needle}%")
                        ->orWhere('email', 'like', "%{$needle}%")
                        ->orWhere('office', 'like', "%{$needle}%")
                        ->orWhere('position', 'like', "%{$needle}%")
                        ->orWhere('designation', 'like', "%{$needle}%");
                });
            })
            ->orderByRaw("case when access_status = 'pending' then 0 when access_status = 'approved' then 1 else 2 end")
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): array => $this->serializeUser($user));

        return Inertia::render('AccessManagement/Index', [
            'users' => $users,
            'filters' => $filters,
            'roleOptions' => Role::query()
                ->whereIn('name', ['Super Admin', 'RROS', 'DRRS', 'DRIMS', 'DRMD AA', 'DRMD Financial Analyst'])
                ->orderByRaw("case name when 'Super Admin' then 0 when 'RROS' then 1 when 'DRRS' then 2 when 'DRIMS' then 3 when 'DRMD AA' then 4 when 'DRMD Financial Analyst' then 5 else 6 end")
                ->pluck('name')
                ->map(fn (string $role): array => ['value' => $role, 'label' => $role])
                ->values(),
            'metrics' => [
                'total' => User::count(),
                'pending' => User::where('access_status', 'pending')->count(),
                'approved' => User::where('access_status', 'approved')->count(),
                'inactive' => User::where('is_active', false)->count(),
            ],
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', 'string', 'exists:roles,name'],
            'access_status' => ['required', 'string', 'in:approved,pending,denied'],
            'office' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ]);

        $old = [
            ...$user->only(['name', 'access_status', 'requested_role', 'office', 'position', 'designation', 'is_active']),
            'roles' => $user->getRoleNames()->values()->all(),
        ];

        $user->forceFill([
            'name' => $validated['name'],
            'access_status' => $validated['access_status'],
            'requested_role' => $validated['access_status'] === 'approved' ? null : $user->requested_role,
            'office' => $validated['office'] ?: $validated['role'],
            'position' => $validated['position'],
            'designation' => $validated['designation'],
            'is_active' => $validated['is_active'],
            'access_approved_at' => $validated['access_status'] === 'approved' ? now() : null,
            'access_approved_by' => $validated['access_status'] === 'approved' ? $request->user()->id : null,
        ])->save();

        if ($validated['access_status'] === 'approved') {
            $user->syncRoles([$validated['role']]);
        } elseif ($validated['access_status'] === 'denied') {
            $user->syncRoles([]);
        }

        $freshUser = $user->fresh('roles');
        $this->audit->log('user.access_updated', $freshUser, $old, [
            ...$freshUser->only(['name', 'access_status', 'requested_role', 'office', 'position', 'designation', 'is_active']),
            'roles' => $freshUser->getRoleNames()->values()->all(),
        ]);

        $message = $validated['access_status'] === 'approved'
            ? "{$freshUser->name} has been granted {$validated['role']} access."
            : "{$freshUser->name}'s access status was updated.";

        return back()->with('success', $message);
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'office' => $user->office,
            'position' => $user->position,
            'designation' => $user->designation,
            'is_active' => $user->is_active,
            'access_status' => $user->access_status,
            'requested_role' => $user->requested_role,
            'roles' => $user->roles->pluck('name')->values(),
            'access_requested_at' => $user->access_requested_at?->toDateTimeString(),
            'access_approved_at' => $user->access_approved_at?->toDateTimeString(),
            'created_at' => $user->created_at?->toDateTimeString(),
        ];
    }
}
