<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditTrailController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $filters = $request->only(['search', 'event', 'user_id']);

        $query = AuditLog::query()
            ->with('user:id,name,email,office')
            ->latest();

        if (filled($filters['user_id'] ?? null)) {
            $query->where('user_id', $filters['user_id']);
        }

        if (filled($filters['event'] ?? null)) {
            $query->where('event', $filters['event']);
        }

        if (filled($filters['search'] ?? null)) {
            $needle = (string) $filters['search'];

            $query->where(function ($builder) use ($needle): void {
                $builder
                    ->where('event', 'like', "%{$needle}%")
                    ->orWhere('auditable_type', 'like', "%{$needle}%")
                    ->orWhere('ip_address', 'like', "%{$needle}%")
                    ->orWhere('old_values', 'like', "%{$needle}%")
                    ->orWhere('new_values', 'like', "%{$needle}%")
                    ->orWhereHas('user', function ($userQuery) use ($needle): void {
                        $userQuery
                            ->where('name', 'like', "%{$needle}%")
                            ->orWhere('email', 'like', "%{$needle}%")
                            ->orWhere('office', 'like', "%{$needle}%");
                    });
            });
        }

        $logs = $query
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AuditLog $log): array => [
                'id' => $log->id,
                'event' => $log->event,
                'event_label' => str($log->event)->replace(['.', '_'], ' ')->title()->toString(),
                'user' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                    'email' => $log->user->email,
                    'office' => $log->user->office,
                ] : null,
                'auditable_type' => $log->auditable_type ? class_basename($log->auditable_type) : null,
                'auditable_id' => $log->auditable_id,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'created_at' => $log->created_at?->toDateTimeString(),
            ]);

        $baseLogs = AuditLog::query();

        return Inertia::render('AuditTrail/Index', [
            'logs' => $logs,
            'filters' => $filters,
            'filterOptions' => [
                'events' => AuditLog::query()->select('event')->distinct()->orderBy('event')->pluck('event'),
                'users' => User::query()->orderBy('name')->get(['id', 'name', 'email', 'office']),
            ],
            'metrics' => [
                'total' => (clone $baseLogs)->count(),
                'today' => (clone $baseLogs)->whereDate('created_at', today())->count(),
                'sign_ins' => (clone $baseLogs)->whereIn('event', ['auth.login', 'auth.sso_login'])->count(),
                'sign_outs' => (clone $baseLogs)->where('event', 'auth.logout')->count(),
            ],
        ]);
    }
}
