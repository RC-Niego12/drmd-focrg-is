<?php

namespace App\Http\Controllers;

use App\Models\AgencyProfile;
use App\Models\RegionalAlert;
use App\Services\AuditLogger;
use App\Services\RegionalAlertNotificationService;
use App\Services\RealtimePublisher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OcdAlertController extends Controller
{
    public function index(Request $request): Response
    {
        $active = app(RegionalAlertNotificationService::class)
            ->ensureDefaultWhite()
            ->load('setter:id,name');

        return Inertia::render('Ocd/AlertManagement', [
            'activeAlert' => $active,
            'alerts' => RegionalAlert::query()
                ->with('setter:id,name')
                ->latest('effective_at')
                ->paginate(12)
                ->withQueryString(),
            'reportingTimelines' => [
                'white' => ['2:00 PM'],
                'blue' => ['10:00 AM', '10:00 PM'],
                'red' => ['10:00 AM', '10:00 PM'],
            ],
        ]);
    }

    public function store(
        Request $request,
        AuditLogger $audit,
        RegionalAlertNotificationService $notifications,
        RealtimePublisher $realtime,
    ): RedirectResponse {
        $data = $request->validate([
            'alert_level' => ['required', Rule::in(['white', 'blue', 'red'])],
            'incident_name' => ['nullable', 'string', 'max:255'],
            'coverage' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:3000'],
            'effective_at' => ['required', 'date'],
            'expires_at' => ['nullable', 'date', 'after:effective_at'],
        ]);

        $previous = RegionalAlert::query()->effective()->latest('effective_at')->first();
        $alert = RegionalAlert::create([...$data, 'set_by' => $request->user()->id]);

        $audit->log('ocd.regional_alert_set', $alert, [], $alert->toArray());
        $notifications->notifyAlertChanged($alert, $previous);
        $recipientIds = $alert->recipients()->pluck('user_id')
            ->merge(\App\Models\User::permission('view regional alert acknowledgements')->pluck('id'))
            ->unique();
        $realtime->usersChanged($recipientIds, 'regional-alert.changed', [
            'alert_id' => $alert->id,
            'alert_level' => $alert->alert_level,
        ]);

        return back()->with('success', strtoupper($alert->alert_level).' Alert is now recorded for '.$alert->coverage.'. LGU notifications were issued.');
    }

    public function acknowledgements(Request $request): Response|JsonResponse
    {
        $alerts = RegionalAlert::query()
            ->with('setter:id,name')
            ->withCount('recipients')
            ->latest('effective_at')
            ->limit(30)
            ->get();
        $requestedAlertId = $request->integer('alert_id');
        $selectedAlert = $requestedAlertId > 0
            ? RegionalAlert::query()->with('setter:id,name')->findOrFail($requestedAlertId)
            : RegionalAlert::query()
                ->with('setter:id,name')
                ->latest('effective_at')
                ->latest('id')
                ->first();
        $recipients = $selectedAlert
            ? $selectedAlert->recipients()->orderBy('recipient_category')->orderBy('recipient_name')->get()
            : collect();
        $serialized = $recipients->map(fn ($recipient): array => [
            'id' => $recipient->id,
            'recipient_category' => $recipient->recipient_category,
            'recipient_name' => $recipient->recipient_name,
            'recipient_role' => $recipient->recipient_role,
            'office' => $recipient->office,
            'lgu_name' => $recipient->lgu_name,
            'notified_at' => $recipient->notified_at?->toIso8601String(),
            'acknowledged_at' => $recipient->acknowledged_at?->toIso8601String(),
            'superseded_at' => $recipient->superseded_at?->toIso8601String(),
            'status' => $recipient->acknowledged_at
                ? 'acknowledged'
                : ($recipient->superseded_at ? 'superseded' : 'awaiting'),
            'response_minutes' => $recipient->acknowledged_at
                ? $recipient->notified_at?->diffInMinutes($recipient->acknowledged_at)
                : null,
        ])->values();
        $summaryFor = function (string $category) use ($serialized): array {
            $rows = $category === 'all'
                ? $serialized
                : $serialized->where('recipient_category', $category);

            return [
                'total' => $rows->count(),
                'acknowledged' => $rows->where('status', 'acknowledged')->count(),
                'awaiting' => $rows->where('status', 'awaiting')->count(),
                'superseded' => $rows->where('status', 'superseded')->count(),
            ];
        };

        $payload = [
            'alerts' => $alerts,
            'selectedAlert' => $selectedAlert,
            'recipients' => $serialized,
            'summary' => [
                'all' => $summaryFor('all'),
                'lgu' => $summaryFor('lgu'),
                'dswd' => $summaryFor('dswd'),
            ],
        ];

        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        return Inertia::render('Ocd/AlertAcknowledgements', $payload);
    }

    public function updateProfile(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'agency_name' => ['required', 'string', 'max:255'],
            'acronym' => ['nullable', 'string', 'max:40'],
            'office_address' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'contact_designation' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'alternate_email' => ['nullable', 'email', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:80'],
            'hotline_number' => ['nullable', 'string', 'max:80'],
            'website' => ['nullable', 'url', 'max:255'],
            'facebook' => ['nullable', 'url', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $profile = AgencyProfile::firstOrNew(['user_id' => $request->user()->id]);
        $old = $profile->exists ? $profile->toArray() : [];

        if ($request->hasFile('logo')) {
            if ($profile->logo_path) {
                Storage::disk('public')->delete($profile->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store('agency-profiles', 'public');
        }
        unset($data['logo']);

        $profile->fill($data)->save();
        $request->user()->update([
            'name' => $profile->acronym ?: $profile->agency_name,
            'office' => $profile->agency_name,
            'contact_number' => $profile->contact_number,
        ]);
        $audit->log('ocd.agency_profile_updated', $profile, $old, $profile->fresh()->toArray());

        return back()->with('success', 'OCD Caraga agency profile updated.');
    }
}
