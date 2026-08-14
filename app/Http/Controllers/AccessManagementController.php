<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\AccessDecisionNotification;
use App\Services\AccessNotificationCenter;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class AccessManagementController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccessNotificationCenter $notificationCenter,
    ) {}

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
                ->whereIn('name', ['Super Admin', 'RROS', 'RROS AA', 'DRRS', 'DRRS AA', 'DRIMS', 'DRMD AA', 'DRMD Chief', 'DRMD Financial Analyst', 'LGU'])
                ->orderByRaw("case name when 'Super Admin' then 0 when 'RROS' then 1 when 'RROS AA' then 2 when 'DRRS' then 3 when 'DRRS AA' then 4 when 'DRIMS' then 5 when 'DRMD AA' then 6 when 'DRMD Chief' then 7 when 'DRMD Financial Analyst' then 8 when 'LGU' then 9 else 10 end")
                ->pluck('name')
                ->map(fn (string $role): array => ['value' => $role, 'label' => $role])
                ->values(),
            'requestableRoleOptions' => $this->notificationCenter->roleOptions(),
            'metrics' => [
                'total' => User::count(),
                'pending' => User::where('access_status', 'pending')->count(),
                'approved' => User::where('access_status', 'approved')->count(),
                'inactive' => User::where('is_active', false)->count(),
                'deleted' => User::onlyTrashed()->count(),
                'drmd' => User::query()->whereDoesntHave('roles', fn ($query) => $query->where('name', 'LGU'))->where(function ($query): void {
                    $query->whereIn('office', ['DRMD', 'DRMD AA', 'DRRS', 'DRRS AA', 'DRIMS', 'RROS', 'RROS AA', 'DRMD Financial Analyst', 'Super Admin'])
                        ->orWhereHas('roles', fn ($roleQuery) => $roleQuery->whereIn('name', ['Super Admin', 'RROS', 'RROS AA', 'DRRS', 'DRRS AA', 'DRIMS', 'DRMD AA', 'DRMD Chief', 'DRMD Financial Analyst']));
                })->count(),
                'lgu' => User::query()->where(function ($query): void {
                    $query->whereNotNull('lgu_psgc_code')
                        ->orWhereHas('roles', fn ($roleQuery) => $roleQuery->where('name', 'LGU'));
                })->count(),
                'outside' => User::query()->whereNull('lgu_psgc_code')->whereDoesntHave('roles', fn ($query) => $query->whereIn('name', ['Super Admin', 'RROS', 'RROS AA', 'DRRS', 'DRRS AA', 'DRIMS', 'DRMD AA', 'DRMD Chief', 'DRMD Financial Analyst', 'LGU']))->count(),
            ],
            'deletedUsers' => User::onlyTrashed()
                ->with('roles:id,name')
                ->orderByDesc('deleted_at')
                ->get()
                ->map(fn (User $user): array => $this->serializeUser($user)),
            'ssoEmployees' => User::query()
                ->whereNotNull('sso_sub')
                ->where('is_active', true)
                ->with('roles:id,name')
                ->orderBy('name')
                ->get()
                ->map(fn (User $employee): array => [
                    'value' => (string) $employee->id,
                    'label' => $employee->name.' · '.($employee->email ?: $employee->username),
                    'roles' => $employee->roles->pluck('name')->values(),
                ])
                ->values(),
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

        if ($validated['role'] === 'Super Admin' && ! $user->hasRole('Super Admin')) {
            throw ValidationException::withMessages([
                'role' => 'Assign new Super Admins through the Caraga Connect SSO employee selector.',
            ]);
        }

        if ($user->hasRole('Super Admin') && $validated['role'] !== 'Super Admin') {
            throw ValidationException::withMessages([
                'role' => 'Super Admin accounts cannot be reassigned through the generic user editor.',
            ]);
        }

        $removingSuperAdmin = $user->hasRole('Super Admin')
            && ($validated['role'] !== 'Super Admin' || $validated['access_status'] !== 'approved' || ! $validated['is_active']);
        if ($removingSuperAdmin && User::role('Super Admin')->whereKeyNot($user->id)->count() === 0) {
            throw ValidationException::withMessages([
                'role' => 'Assign another active Super Admin before changing the last Super Admin account.',
            ]);
        }

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

    public function decide(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['approve', 'deny'])],
            'role' => ['nullable', 'required_if:action,approve', Rule::in(AccessNotificationCenter::REQUESTABLE_ROLES)],
            'response_message' => ['nullable', 'required_if:action,deny', 'string', 'max:1000'],
        ]);

        $approved = $validated['action'] === 'approve';
        $role = $approved ? $validated['role'] : null;
        $old = [
            ...$user->only(['access_status', 'requested_role', 'access_response_message', 'access_decided_at']),
            'roles' => $user->getRoleNames()->values()->all(),
        ];

        DB::transaction(function () use ($request, $user, $validated, $approved, $role): void {
            $user->forceFill([
                'access_status' => $approved ? 'approved' : 'denied',
                'requested_role' => $approved ? null : $user->requested_role,
                'office' => $approved ? $role : $user->office,
                'is_active' => $approved ? true : $user->is_active,
                'access_approved_at' => $approved ? now() : null,
                'access_approved_by' => $request->user()->id,
                'access_response_message' => $validated['response_message'] ?? null,
                'access_decided_at' => now(),
            ])->save();

            $user->syncRoles([$approved ? $role : 'guest']);

            $request->user()->unreadNotifications
                ->filter(fn ($notification): bool => (int) data_get($notification->data, 'access_user_id') === $user->id)
                ->each->markAsRead();
        });

        $freshUser = $user->fresh('roles');
        $freshUser->notify(new AccessDecisionNotification(
            $freshUser->access_status,
            $role,
            $freshUser->access_response_message,
        ));

        $this->audit->log('user.access_decided', $freshUser, $old, [
            ...$freshUser->only(['access_status', 'requested_role', 'access_response_message', 'access_decided_at']),
            'roles' => $freshUser->getRoleNames()->values()->all(),
        ], $request->user()->id);

        return back()->with('success', $approved
            ? "{$freshUser->name} was granted {$role} access."
            : "{$freshUser->name}'s access request was disapproved.");
    }

    public function assignSuperAdmin(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $employee = User::query()
            ->whereKey($validated['user_id'])
            ->whereNotNull('sso_sub')
            ->where('is_active', true)
            ->firstOrFail();

        $oldRoles = $employee->getRoleNames()->values()->all();
        $employee->forceFill([
            'access_status' => 'approved',
            'requested_role' => null,
            'access_approved_at' => now(),
            'access_approved_by' => $request->user()->id,
            'access_response_message' => 'Assigned as a DROMIS Super Admin.',
            'access_decided_at' => now(),
        ])->save();
        $employee->syncRoles(['Super Admin']);
        $employee->notify(new AccessDecisionNotification('approved', 'Super Admin', 'You were assigned as a DROMIS Super Admin.'));

        $this->audit->log('user.super_admin_assigned', $employee, ['roles' => $oldRoles], [
            'roles' => ['Super Admin'],
            'assigned_by' => $request->user()->id,
        ], $request->user()->id);

        return back()->with('success', "{$employee->name} is now a Super Admin.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403);
        $this->ensureCanDeleteUser($request->user(), $user);

        $old = [
            ...$user->only(['name', 'email', 'access_status', 'is_active', 'deleted_at']),
            'roles' => $user->getRoleNames()->values()->all(),
        ];

        DB::transaction(function () use ($request, $user, $old): void {
            $user->forceFill(['is_active' => false])->save();
            $user->delete();

            $this->audit->log('user.soft_deleted', $user, $old, [
                ...$user->only(['name', 'email', 'access_status', 'is_active', 'deleted_at']),
                'deleted_by' => $request->user()->id,
            ], $request->user()->id);
        });

        return back()->with('success', "{$user->name} was archived.");
    }

    public function restore(Request $request, int $user): RedirectResponse
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403);

        $deletedUser = User::withTrashed()->findOrFail($user);
        abort_unless($deletedUser->trashed(), 404);

        $old = [
            ...$deletedUser->only(['name', 'email', 'access_status', 'is_active', 'deleted_at']),
            'roles' => $deletedUser->getRoleNames()->values()->all(),
        ];

        DB::transaction(function () use ($request, $deletedUser, $old): void {
            $deletedUser->restore();
            $deletedUser->forceFill(['is_active' => true])->save();

            $this->audit->log('user.restored', $deletedUser, $old, [
                ...$deletedUser->only(['name', 'email', 'access_status', 'is_active', 'deleted_at']),
                'restored_by' => $request->user()->id,
            ], $request->user()->id);
        });

        return back()->with('success', "{$deletedUser->name} was restored.");
    }

    public function forceDelete(Request $request, int $user): RedirectResponse
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403);

        $deletedUser = User::withTrashed()->findOrFail($user);
        abort_unless($deletedUser->trashed(), 404);

        $validated = $request->validate([
            'confirmation' => ['required', 'string', Rule::in([$deletedUser->email])],
        ]);

        $this->ensureCanDeleteUser($request->user(), $deletedUser);

        $old = [
            ...$deletedUser->only(['name', 'email', 'access_status', 'is_active', 'deleted_at']),
            'roles' => $deletedUser->getRoleNames()->values()->all(),
            'confirmation' => $validated['confirmation'],
        ];

        try {
            DB::transaction(function () use ($request, $deletedUser, $old): void {
                $deletedUser->syncRoles([]);
                $this->audit->log('user.force_deleted', $deletedUser, $old, [
                    'force_deleted_by' => $request->user()->id,
                ], $request->user()->id);
                $deletedUser->forceDelete();
            });
        } catch (QueryException) {
            throw ValidationException::withMessages([
                'confirmation' => 'This user is still referenced by system records and cannot be permanently deleted. Keep the user archived instead.',
            ]);
        }

        return back()->with('success', "{$deletedUser->name} was permanently deleted.");
    }

    private function serializeUser(User $user): array
    {
        $roles = $user->roles->pluck('name')->values();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $user->avatar,
            'office' => $user->office,
            'position' => $user->position,
            'designation' => $user->designation,
            'is_active' => $user->is_active,
            'access_status' => $user->access_status,
            'requested_role' => $user->requested_role,
            'roles' => $roles,
            'category' => $this->userCategory($user, $roles->all()),
            'lgu_psgc_code' => $user->lgu_psgc_code,
            'lgu_level' => $user->lgu_level,
            'lgu_name' => $user->lgu_name,
            'access_requested_at' => $user->access_requested_at?->toDateTimeString(),
            'access_approved_at' => $user->access_approved_at?->toDateTimeString(),
            'access_decided_at' => $user->access_decided_at?->toDateTimeString(),
            'response_message' => $user->access_response_message,
            'created_at' => $user->created_at?->toDateTimeString(),
            'deleted_at' => $user->deleted_at?->toDateTimeString(),
        ];
    }

    private function userCategory(User $user, array $roles): string
    {
        if ($user->lgu_psgc_code || in_array('LGU', $roles, true)) {
            return 'lgu';
        }

        $drmdRoles = ['Super Admin', 'RROS', 'RROS AA', 'DRRS', 'DRRS AA', 'DRIMS', 'DRMD AA', 'DRMD Chief', 'DRMD Financial Analyst'];
        if (array_intersect($roles, $drmdRoles) || in_array($user->office, ['DRMD', 'DRMD AA', 'DRRS', 'DRRS AA', 'DRIMS', 'RROS', 'RROS AA', 'DRMD Financial Analyst', 'Super Admin'], true)) {
            return 'drmd';
        }

        return 'outside';
    }

    private function ensureCanDeleteUser(User $actor, User $user): void
    {
        if ($actor->is($user)) {
            throw ValidationException::withMessages([
                'user' => 'You cannot delete your own Super Admin account.',
            ]);
        }

        if ($user->hasRole('Super Admin') && $this->activeSuperAdminCountExcluding($user) === 0) {
            throw ValidationException::withMessages([
                'user' => 'Assign another active Super Admin before deleting this account.',
            ]);
        }
    }

    private function activeSuperAdminCountExcluding(User $user): int
    {
        return User::role('Super Admin')
            ->whereKeyNot($user->id)
            ->where('access_status', 'approved')
            ->where('is_active', true)
            ->count();
    }
}
