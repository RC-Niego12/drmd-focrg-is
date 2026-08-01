<?php

namespace App\Http\Controllers;

use App\Models\EmployeeAreaOfResponsibility;
use App\Models\PsgcAddress;
use App\Services\AuditLogger;
use App\Services\SSOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class UserProfileController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SSOAuthService $sso,
    ) {}

    public function syncMyPortal(Request $request): RedirectResponse
    {
        $user = $request->user();
        $old = $user->only($this->profileFields());

        try {
            $myPortalProfile = $this->sso->fetchMyPortalProfileForIdentity([
                'username' => $user->username,
                'email' => $user->email,
                'id_number' => $user->id_number,
                'name' => $user->name,
            ]);
            $mapped = $this->sso->mapProfileToUserFields($myPortalProfile);
            $payload = collect($mapped)
                ->only($this->profileFields())
                ->filter(fn ($value): bool => filled($value))
                ->all();

            if ($payload === []) {
                return back()->with('error', 'MyPortal returned no employee profile fields.');
            }

            $existingSsoPayload = is_array($user->sso_profile_payload) ? $user->sso_profile_payload : [];

            $user->forceFill([
                ...$payload,
                'sso_profile_payload' => [
                    ...$existingSsoPayload,
                    'myportal' => $myPortalProfile,
                    'myportal_checked_at' => now()->toISOString(),
                ],
            ])->save();

            $this->audit->log('user.myportal_profile_synced', $user, $old, $user->fresh()->only($this->profileFields()), $user->id);

            return back()->with('success', 'Employee profile refreshed from MyPortal.');
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }
    }

    public function occupiedAorScopes(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user && $user->hasAnyRole(['DRRS', 'DRIMS', 'Super Admin']), 403, 'Only DRRS and DRIMS users can view occupied area-of-responsibility scopes.');

        $peerRoles = EmployeeAreaOfResponsibility::aorPeerRolesFor($user);
        if ($peerRoles === []) {
            return response()->json([]);
        }

        $occupied = EmployeeAreaOfResponsibility::query()
            ->whereHas('user', function ($query) use ($peerRoles): void {
                $query->where('is_active', true)
                    ->whereHas('roles', function ($rolesQuery) use ($peerRoles): void {
                        $rolesQuery->whereIn('name', $peerRoles);
                    });
            })
            ->where('user_id', '!=', $user->id)
            ->select(['level', 'psgc_code'])
            ->get()
            ->map(fn (EmployeeAreaOfResponsibility $scope): array => [
                'level' => $scope->level,
                'psgc_code' => $scope->psgc_code,
            ])
            ->values()
            ->all();

        return response()->json($occupied);
    }

    public function updateAor(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user && $user->hasAnyRole(['DRRS', 'DRIMS', 'Super Admin']), 403, 'Only DRRS and DRIMS users can update their area of responsibility.');

        $validated = $request->validate([
            'aor_provinces' => ['nullable', 'array'],
            'aor_provinces.*' => ['nullable', 'string', 'max:255'],
            'aor_districts' => ['nullable', 'array'],
            'aor_districts.*' => ['nullable', 'string', 'max:255'],
            'aor_cities_municipalities' => ['nullable', 'array'],
            'aor_cities_municipalities.*' => ['nullable', 'string', 'max:255'],
        ]);

        $sanitizeAorValues = function (mixed $value): array {
            return collect(is_array($value) ? $value : [])
                ->map(fn ($item) => trim((string) $item))
                ->filter(fn (string $item) => $item !== '')
                ->unique()
                ->values()
                ->all();
        };

        $provinceCodes = $sanitizeAorValues($validated['aor_provinces'] ?? []);
        $districtCodes = $sanitizeAorValues($validated['aor_districts'] ?? []);
        $cityCodes = $sanitizeAorValues($validated['aor_cities_municipalities'] ?? []);

        // Keep only cities that belong to explicitly selected districts.
        if ($cityCodes !== [] && $districtCodes !== []) {
            $allowedDistricts = collect($districtCodes)->flip();
            $cityCodes = PsgcAddress::query()
                ->whereIn('code', $cityCodes)
                ->get(['code', 'district_code'])
                ->filter(fn (PsgcAddress $city): bool => $city->district_code && $allowedDistricts->has((string) $city->district_code))
                ->pluck('code')
                ->map(fn ($code) => (string) $code)
                ->values()
                ->all();
        } elseif ($districtCodes === []) {
            $cityCodes = [];
        }

        if ($districtCodes !== []) {
            $districtParents = PsgcAddress::query()
                ->whereIn('code', $districtCodes)
                ->pluck('parent_code')
                ->filter()
                ->map(fn ($code) => (string) $code)
                ->all();

            $provinceCodes = $sanitizeAorValues([...$provinceCodes, ...$districtParents]);
        }

        if ($provinceCodes === []) {
            return back()->withErrors([
                'aor' => 'Province is required. Select at least one province.',
            ])->withInput();
        }

        if ($districtCodes === []) {
            return back()->withErrors([
                'aor' => 'District is required. Select at least one district for each selected province.',
            ])->withInput();
        }

        if ($cityCodes === []) {
            return back()->withErrors([
                'aor' => 'Municipality / City is required. Select at least one city/municipality for each selected district.',
            ])->withInput();
        }

        $districtRows = PsgcAddress::query()
            ->whereIn('code', $districtCodes)
            ->get(['code', 'name', 'parent_code'])
            ->keyBy('code');

        foreach ($provinceCodes as $provinceCode) {
            $hasDistrict = $districtRows->contains(fn (PsgcAddress $district): bool => (string) $district->parent_code === (string) $provinceCode);
            if (! $hasDistrict) {
                $provinceName = PsgcAddress::query()->where('code', $provinceCode)->value('name') ?: $provinceCode;

                return back()->withErrors([
                    'aor' => "Select at least one district under {$provinceName}.",
                ])->withInput();
            }
        }

        $cityRows = PsgcAddress::query()
            ->whereIn('code', $cityCodes)
            ->get(['code', 'name', 'district_code'])
            ->keyBy('code');

        foreach ($districtCodes as $districtCode) {
            $hasCity = $cityRows->contains(fn (PsgcAddress $city): bool => (string) $city->district_code === (string) $districtCode);
            if (! $hasCity) {
                $districtName = $districtRows->get($districtCode)?->name ?: $districtCode;

                return back()->withErrors([
                    'aor' => "Select at least one city/municipality under {$districtName}.",
                ])->withInput();
            }
        }

        $scopeConflicts = EmployeeAreaOfResponsibility::scopeConflictsForUser($user, $provinceCodes, $districtCodes, $cityCodes);
        if ($scopeConflicts !== []) {
            $conflictMessages = collect($scopeConflicts)
                ->map(function (array $conflict): string {
                    $label = PsgcAddress::query()->where('code', $conflict['psgc_code'])->value('name') ?: $conflict['psgc_code'];

                    return sprintf(
                        '%s "%s" is already assigned to %s (%s).',
                        ucfirst(str_replace('_', ' ', $conflict['level'])),
                        $label,
                        $conflict['owner_name'],
                        $conflict['owner_office']
                    );
                })
                ->join(' ');

            $peerLabel = implode('/', EmployeeAreaOfResponsibility::aorPeerRolesFor($user) ?: ['user']);

            return back()->withErrors([
                'aor' => "The selected AOR scope is already assigned to another {$peerLabel} user and cannot be reused. ".$conflictMessages,
            ])->withInput();
        }

        $user->forceFill([
            'aor_provinces' => $provinceCodes,
            'aor_districts' => $districtCodes,
            'aor_cities_municipalities' => $cityCodes,
        ])->save();

        EmployeeAreaOfResponsibility::syncForUser($user, $provinceCodes, $districtCodes, $cityCodes);

        return back()->with('success', 'Area of responsibility updated.');
    }

    public function photo(Request $request): Response
    {
        $avatar = $this->sso->normalizeAvatarUrl($request->user()?->avatar);

        abort_if(blank($avatar), 404);

        if (str_starts_with((string) $avatar, 'data:image/')) {
            [$meta, $encoded] = explode(',', (string) $avatar, 2) + [null, null];
            $mime = str($meta)->between('data:', ';base64')->value() ?: 'image/png';

            return response(base64_decode((string) $encoded, true) ?: '', 200)
                ->header('Content-Type', $mime)
                ->header('Cache-Control', 'private, max-age=600');
        }

        abort_unless($this->sso->isTrustedIdentityUrl((string) $avatar), 403);

        $remote = Http::withHeaders([
            'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
            'User-Agent' => 'DROMIS/1.0 employee-photo-proxy',
            'Referer' => config('app.url'),
        ])
            ->timeout(10)
            ->when(! config('services.cc_idp.verify_ssl'), fn ($pending) => $pending->withoutVerifying())
            ->get((string) $avatar);

        abort_unless($remote->ok(), 404);

        $contentType = $remote->header('Content-Type') ?: 'image/jpeg';
        abort_unless(str_starts_with(strtolower($contentType), 'image/'), 415);

        return response($remote->body(), 200)
            ->header('Content-Type', $contentType)
            ->header('Cache-Control', 'private, max-age=600');
    }

    private function profileFields(): array
    {
        return [
            'name',
            'username',
            'email',
            'id_number',
            'office',
            'position',
            'designation',
            'area_of_assignment',
            'employment_status',
            'contact_number',
            'mobile_no',
            'avatar',
        ];
    }
}
