<?php

namespace App\Services;

use App\Models\AssistanceRequest;
use App\Models\AuditLog;
use App\Models\DispatchPlan;
use App\Models\DromicReport;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\StandbyFund;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AiRogerContextService
{
    public function forUser(User $user, string $question, ?string $currentUrl = null): string
    {
        $sections = [
            'Access control note: The database context below is pre-filtered by DROMIS. AI Roger cannot run arbitrary SQL and must not infer hidden records.',
            'Current page: '.($currentUrl ?: 'not supplied'),
        ];

        if ($this->canInventory($user)) {
            $sections[] = $this->inventoryContext($question);
            $sections[] = $this->warehouseContext($question);
        }

        if ($user->can('manage near expiry')) {
            $sections[] = $this->nearExpiryContext();
        }

        if ($this->canRequests($user)) {
            $sections[] = $this->requestContext($user, $question);
        }

        if ($user->can('submit lgu dromic requests')) {
            $sections[] = $this->lguContext($user, $question);
        }

        if ($user->can('route lgu dromic requests')) {
            $sections[] = $this->lguRoutingContext($question);
        }

        if ($user->can('manage dromic reports')) {
            $sections[] = $this->dromicContext($question);
        }

        if ($user->can('manage dispatches')) {
            $sections[] = $this->dispatchContext($question);
        }

        if ($user->can('manage standby funds')) {
            $sections[] = $this->standbyFundContext();
        }

        if ($user->can('manage users')) {
            $sections[] = $this->userAccessContext($question);
        }

        if ($user->can('view audit logs')) {
            $sections[] = $this->auditContext();
        }

        return collect($sections)
            ->filter()
            ->map(fn (string $section): string => Str::limit($section, 2200, "\n[section truncated]"))
            ->implode("\n\n---\n\n");
    }

    private function canInventory(User $user): bool
    {
        return $user->can('manage inventory') || $user->can('view dashboards') || $user->can('manage warehouses');
    }

    private function canRequests(User $user): bool
    {
        return $user->can('encode requests')
            || $user->can('monitor requests')
            || $user->can('process requests')
            || $user->can('submit drmd aa requests');
    }

    private function inventoryContext(string $question): string
    {
        $summary = [
            'active_items' => InventoryItem::query()->where('status', 'active')->count(),
            'total_items' => InventoryItem::count(),
            'total_batches' => InventoryBatch::count(),
            'recent_transactions' => InventoryTransaction::count(),
        ];

        $items = InventoryItem::query()
            ->when($this->keywords($question)->isNotEmpty(), function ($query) use ($question): void {
                $query->where(function ($builder) use ($question): void {
                    foreach ($this->keywords($question) as $term) {
                        $builder->orWhere('name', 'like', "%{$term}%")
                            ->orWhere('category', 'like', "%{$term}%");
                    }
                });
            })
            ->orderBy('name')
            ->limit(8)
            ->get(['name', 'category', 'unit', 'status'])
            ->map(fn (InventoryItem $item): string => "{$item->name} ({$item->category}, {$item->unit}, {$item->status})")
            ->implode('; ');

        return "Inventory database summary: ".json_encode($summary)."\nRelevant inventory items: ".($items ?: 'No keyword-matched inventory items.');
    }

    private function warehouseContext(string $question): string
    {
        $status = Warehouse::query()->selectRaw("coalesce(nullif(status, ''), 'unspecified') as label, count(*) as total")->groupBy('label')->pluck('total', 'label');
        $province = Warehouse::query()->selectRaw("coalesce(nullif(province, ''), 'unspecified') as label, count(*) as total")->groupBy('label')->orderByDesc('total')->limit(8)->pluck('total', 'label');
        $warehouses = Warehouse::query()
            ->when($this->keywords($question)->isNotEmpty(), function ($query) use ($question): void {
                $query->where(function ($builder) use ($question): void {
                    foreach ($this->keywords($question) as $term) {
                        $builder->orWhere('name', 'like', "%{$term}%")
                            ->orWhere('province', 'like', "%{$term}%")
                            ->orWhere('municipality', 'like', "%{$term}%");
                    }
                });
            })
            ->orderBy('province')
            ->limit(8)
            ->get(['name', 'province', 'municipality', 'status', 'warehouse_type'])
            ->map(fn (Warehouse $warehouse): string => "{$warehouse->display_name} ({$warehouse->warehouse_type}, {$warehouse->status})")
            ->implode('; ');

        return "Warehouse database summary: status=".json_encode($status)."; by province=".json_encode($province)."\nRelevant warehouses: ".($warehouses ?: 'No keyword-matched warehouses.');
    }

    private function nearExpiryContext(): string
    {
        $rows = InventoryBatch::query()
            ->with(['item:id,name,unit', 'warehouse:id,name,province,municipality'])
            ->nearExpiry()
            ->orderBy('expiration_date')
            ->limit(8)
            ->get()
            ->map(fn (InventoryBatch $batch): string => ($batch->item?->name ?? 'Item').' at '.($batch->warehouse?->display_name ?? 'warehouse').' expires '.$batch->expiration_date?->toDateString().' available '.$batch->available_quantity.' '.$batch->item?->unit)
            ->implode('; ');

        return "Near-expiry inventory visible to this user: ".($rows ?: 'No near-expiry batches found.');
    }

    private function requestContext(User $user, string $question): string
    {
        $query = AssistanceRequest::query()->with('incident:id,name');
        if ($user->can('submit drmd aa requests') && ! $user->can('monitor requests') && ! $user->can('encode requests') && ! $user->can('process requests')) {
            $query->where('encoded_by', $user->id);
        }

        $summary = (clone $query)->selectRaw("coalesce(nullif(status, ''), 'unspecified') as label, count(*) as total")->groupBy('label')->pluck('total', 'label');
        $records = $this->filterRequestsByQuestion(clone $query, $question)
            ->latest('submitted_at')
            ->limit(8)
            ->get(['id', 'reference_number', 'requesting_agency', 'province', 'municipality', 'purpose', 'status', 'submitted_at', 'incident_id'])
            ->map(fn (AssistanceRequest $request): string => "{$request->reference_number}: {$request->requesting_agency}, {$request->purpose}, {$request->status}, {$request->province}/{$request->municipality}")
            ->implode('; ');

        return "Request records visible to this user: status summary=".json_encode($summary)."\nRelevant requests: ".($records ?: 'No keyword-matched request records.');
    }

    private function lguContext(User $user, string $question): string
    {
        $query = AssistanceRequest::query()
            ->where('submission_type', 'lgu_dromic_relief_request');

        if (in_array(strtoupper((string) $user->lgu_level), ['PROVINCE', 'PLGU'], true)) {
            $query->where('province', $user->lgu_name);
        } else {
            $query->where('lgu_submitted_by', $user->id);
        }

        $summaryRows = (clone $query)->get(['municipality', 'affected_families', 'lgu_dromic_payload', 'lgu_routing_status']);
        $records = $this->filterRequestsByQuestion(clone $query, $question)
            ->latest('submitted_at')
            ->limit(8)
            ->get(['reference_number', 'requesting_agency', 'province', 'municipality', 'status', 'lgu_routing_status', 'submitted_at', 'affected_families'])
            ->map(fn (AssistanceRequest $request): string => "{$request->reference_number}: {$request->requesting_agency}, {$request->municipality}, affected families ".($request->affected_families ?? 'n/a').', status '.($request->lgu_routing_status ?? $request->status))
            ->implode('; ');

        return "LGU DROMIC context visible to this user: reports={$summaryRows->count()}, cities/municipalities={$summaryRows->pluck('municipality')->filter()->unique()->count()}, affected_families=".$summaryRows->sum('affected_families').", with_relief_request=".$summaryRows->filter(fn ($row): bool => (bool) data_get($row->lgu_dromic_payload, 'has_relief_request'))->count()."\nRelevant LGU reports: ".($records ?: 'No keyword-matched LGU reports.');
    }

    private function lguRoutingContext(string $question): string
    {
        $query = AssistanceRequest::query()
            ->where('submission_type', 'lgu_dromic_relief_request')
            ->where('lgu_dromic_payload->has_relief_request', true);

        $summary = (clone $query)->selectRaw("coalesce(nullif(lgu_routing_status, ''), 'unspecified') as label, count(*) as total")->groupBy('label')->pluck('total', 'label');
        $records = $this->filterRequestsByQuestion(clone $query, $question)
            ->latest('submitted_at')
            ->limit(8)
            ->get(['reference_number', 'requesting_agency', 'province', 'municipality', 'lgu_routing_status', 'drmd_assigned_section'])
            ->map(fn (AssistanceRequest $request): string => "{$request->reference_number}: {$request->requesting_agency}, {$request->lgu_routing_status}, assigned section ".($request->drmd_assigned_section ?: 'not yet assigned'))
            ->implode('; ');

        return "LGU relief routing context visible to this user: ".json_encode($summary)."\nRelevant routed LGU requests: ".($records ?: 'No keyword-matched routed LGU requests.');
    }

    private function dromicContext(string $question): string
    {
        $summary = DromicReport::query()->selectRaw("coalesce(nullif(status, ''), 'unspecified') as label, count(*) as total")->groupBy('label')->pluck('total', 'label');
        $records = DromicReport::query()
            ->with('request:id,reference_number,requesting_agency')
            ->when($this->keywords($question)->isNotEmpty(), function ($query) use ($question): void {
                $query->where(function ($builder) use ($question): void {
                    foreach ($this->keywords($question) as $term) {
                        $builder->orWhere('report_number', 'like', "%{$term}%")
                            ->orWhere('affected_lgu', 'like', "%{$term}%")
                            ->orWhere('status', 'like', "%{$term}%");
                    }
                });
            })
            ->latest()
            ->limit(8)
            ->get(['report_number', 'affected_lgu', 'status', 'date_released', 'request_id'])
            ->map(fn (DromicReport $report): string => "{$report->report_number}: {$report->affected_lgu}, {$report->status}, request ".($report->request?->reference_number ?? 'n/a'))
            ->implode('; ');

        return "DROMIC monitoring context visible to this user: ".json_encode($summary)."\nRelevant DROMIC reports: ".($records ?: 'No keyword-matched DROMIC reports.');
    }

    private function dispatchContext(string $question): string
    {
        $summary = DispatchPlan::query()->selectRaw("coalesce(nullif(status, ''), 'unspecified') as label, count(*) as total")->groupBy('label')->pluck('total', 'label');
        $records = DispatchPlan::query()
            ->when($this->keywords($question)->isNotEmpty(), function ($query) use ($question): void {
                $query->where(function ($builder) use ($question): void {
                    foreach ($this->keywords($question) as $term) {
                        $builder->orWhere('dispatch_number', 'like', "%{$term}%")
                            ->orWhere('destination', 'like', "%{$term}%")
                            ->orWhere('receiving_agency_lgu', 'like', "%{$term}%");
                    }
                });
            })
            ->latest()
            ->limit(8)
            ->get(['dispatch_number', 'destination', 'receiving_agency_lgu', 'dispatch_date', 'status'])
            ->map(fn (DispatchPlan $dispatch): string => "{$dispatch->dispatch_number}: {$dispatch->receiving_agency_lgu} to {$dispatch->destination}, {$dispatch->dispatch_date}, {$dispatch->status}")
            ->implode('; ');

        return "Dispatch context visible to this user: ".json_encode($summary)."\nRelevant dispatches: ".($records ?: 'No keyword-matched dispatches.');
    }

    private function standbyFundContext(): string
    {
        $rows = StandbyFund::query()
            ->latest('updated_at')
            ->limit(8)
            ->get()
            ->map(fn (StandbyFund $fund): string => collect($fund->getAttributes())->only(['office', 'source', 'amount', 'cell_reference', 'synced_at', 'updated_at'])->filter()->toJson())
            ->implode('; ');

        return "Standby fund context visible to this user: ".($rows ?: 'No standby fund rows found.');
    }

    private function userAccessContext(string $question): string
    {
        $summary = User::query()->selectRaw("coalesce(nullif(access_status, ''), 'unspecified') as label, count(*) as total")->groupBy('label')->pluck('total', 'label');
        $pending = User::query()
            ->where('access_status', 'pending')
            ->latest('access_requested_at')
            ->limit(8)
            ->get(['name', 'email', 'requested_role', 'office', 'access_requested_at'])
            ->map(fn (User $user): string => "{$user->name} ({$user->email}) requested {$user->requested_role}")
            ->implode('; ');

        return "User access context visible to manage-users roles: status summary=".json_encode($summary)."\nPending access requests: ".($pending ?: 'No pending access requests.');
    }

    private function auditContext(): string
    {
        $records = AuditLog::query()
            ->with('user:id,name')
            ->latest()
            ->limit(8)
            ->get(['event', 'user_id', 'created_at'])
            ->map(fn (AuditLog $log): string => "{$log->created_at?->toDateTimeString()} {$log->event} by ".($log->user?->name ?? 'System'))
            ->implode('; ');

        return "Recent audit log context visible to this user: ".($records ?: 'No audit logs found.');
    }

    private function filterRequestsByQuestion($query, string $question)
    {
        $keywords = $this->keywords($question);
        if ($keywords->isEmpty()) {
            return $query;
        }

        return $query->where(function ($builder) use ($keywords): void {
            foreach ($keywords as $term) {
                $builder->orWhere('reference_number', 'like', "%{$term}%")
                    ->orWhere('requesting_agency', 'like', "%{$term}%")
                    ->orWhere('province', 'like', "%{$term}%")
                    ->orWhere('municipality', 'like', "%{$term}%")
                    ->orWhere('purpose', 'like', "%{$term}%")
                    ->orWhere('status', 'like', "%{$term}%");
            }
        });
    }

    private function keywords(string $question): Collection
    {
        return str($question)
            ->lower()
            ->replaceMatches('/[^a-z0-9\s-]/', ' ')
            ->explode(' ')
            ->map(fn (string $word): string => trim($word))
            ->filter(fn (string $word): bool => strlen($word) >= 4 && ! in_array($word, [
                'what', 'when', 'where', 'which', 'about', 'please', 'show', 'give', 'list', 'tell', 'dromis',
                'report', 'reports', 'request', 'requests', 'status', 'system',
            ], true))
            ->take(8)
            ->values();
    }
}
