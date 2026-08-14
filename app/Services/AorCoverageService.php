<?php

namespace App\Services;

use App\Models\AssistanceRequest;
use App\Models\EmployeeAreaOfResponsibility;
use App\Models\PsgcAddress;
use App\Models\User;
use Illuminate\Support\Collection;

class AorCoverageService
{
    public function coversLocation(User $user, ?string $psgcCode, ?string $province = null, ?string $municipality = null, ?string $roleScope = null): bool
    {
        if ($user->hasRole('Super Admin') && $roleScope === null) {
            return true;
        }

        if ($roleScope === 'DRRS' && ! $user->hasRole('DRRS') && ! $user->hasRole('Super Admin')) {
            return false;
        }

        if ($roleScope === 'DRIMS' && ! $user->hasRole('DRIMS') && ! $user->hasRole('Super Admin')) {
            return false;
        }

        if ($roleScope === null && ! $user->hasAnyRole(['DRRS', 'DRIMS', 'Super Admin'])) {
            return false;
        }

        if ($user->hasRole('Super Admin') && $roleScope !== null) {
            return true;
        }

        $codes = $this->normalizeUserAorCodes($user);
        if ($codes['cities'] === [] && $codes['districts'] === [] && $codes['provinces'] === []) {
            return false;
        }

        $resolved = $this->resolveLocationCodes($psgcCode, $province, $municipality);
        if ($resolved === null) {
            return false;
        }

        if ($resolved['city'] && in_array($resolved['city'], $codes['cities'], true)) {
            return true;
        }

        if ($resolved['district'] && in_array($resolved['district'], $codes['districts'], true)) {
            return true;
        }

        if ($resolved['province'] && in_array($resolved['province'], $codes['provinces'], true)) {
            // Province-only claim still requires the user to own the city/district
            // when more specific scopes exist; otherwise province membership counts.
            if ($codes['cities'] === [] && $codes['districts'] === []) {
                return true;
            }
        }

        return false;
    }

    public function coversRequest(User $user, AssistanceRequest $request, ?string $roleScope = null): bool
    {
        return $this->coversLocation(
            $user,
            $request->lgu_psgc_code,
            $request->province,
            $request->municipality,
            $roleScope
        );
    }

    /**
     * @return Collection<int, User>
     */
    public function ownersForLocation(?string $psgcCode, ?string $province = null, ?string $municipality = null, string $roleScope = 'DRRS'): Collection
    {
        $resolved = $this->resolveLocationCodes($psgcCode, $province, $municipality);
        if ($resolved === null) {
            return collect();
        }

        $candidateCodes = array_values(array_filter([
            $resolved['city'],
            $resolved['district'],
            $resolved['province'],
        ]));

        if ($candidateCodes === []) {
            return collect();
        }

        $ownerIds = EmployeeAreaOfResponsibility::query()
            ->whereIn('psgc_code', $candidateCodes)
            ->whereHas('user', function ($query) use ($roleScope): void {
                $query->where('is_active', true)
                    ->whereHas('roles', fn ($roles) => $roles->where('name', $roleScope));
            })
            ->pluck('user_id')
            ->unique()
            ->values();

        if ($ownerIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $ownerIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'office', 'aor_provinces', 'aor_districts', 'aor_cities_municipalities'])
            ->filter(fn (User $user): bool => $this->coversLocation($user, $psgcCode, $province, $municipality, $roleScope))
            ->values();
    }

    public function ownersForRequest(AssistanceRequest $request, string $roleScope = 'DRRS'): Collection
    {
        return $this->ownersForLocation($request->lgu_psgc_code, $request->province, $request->municipality, $roleScope);
    }

    public function primaryOwnerForRequest(AssistanceRequest $request, string $roleScope = 'DRRS'): ?User
    {
        $owners = $this->ownersForRequest($request, $roleScope);
        if ($owners->isEmpty()) {
            return null;
        }

        // Prefer the city-level owner when available.
        $resolved = $this->resolveLocationCodes($request->lgu_psgc_code, $request->province, $request->municipality);
        if ($resolved && $resolved['city']) {
            $cityOwner = $owners->first(function (User $user) use ($resolved): bool {
                return in_array($resolved['city'], $this->normalizeUserAorCodes($user)['cities'], true);
            });
            if ($cityOwner) {
                return $cityOwner;
            }
        }

        return $owners->first();
    }

    /**
     * @return array{can_create: bool, can_act_on_behalf: bool, can_access_documents: bool, is_owner: bool, acted_by: ?array, primary_owner: ?array, reason: ?string}
     */
    public function assessmentAccessFor(User $user, AssistanceRequest $request): array
    {
        // Assessment acting is a DRRS workflow. DRIMS AOR is independent and must not
        // be used for DRRS ownership / act-on-behalf decisions.
        $isPrivileged = $user->hasRole('Super Admin');
        $isDrrs = $user->hasRole('DRRS');
        $covers = $isDrrs && $this->coversRequest($user, $request, 'DRRS');
        $primaryOwner = $this->primaryOwnerForRequest($request, 'DRRS');
        $actedById = $request->assessment_acted_by;
        if (! $actedById && filled($request->assessment_status)) {
            $assignedName = trim((string) ($request->assigned_social_worker
                ?: data_get($request->assessment_form_data, 'prepared_by')));
            if ($assignedName !== '') {
                $actedById = User::query()
                    ->whereRaw('lower(name) = ?', [mb_strtolower($assignedName)])
                    ->value('id');
            }
        }
        $actedBy = $actedById ? User::query()->find($actedById, ['id', 'name', 'office']) : null;
        $hasAssessment = filled($request->assessment_status);

        $actedByPayload = $actedBy ? [
            'id' => $actedBy->id,
            'name' => $actedBy->name,
            'office' => $actedBy->office,
            'on_behalf_of' => $request->assessment_on_behalf_of
                ? User::query()->find($request->assessment_on_behalf_of, ['id', 'name'])?->only(['id', 'name'])
                : null,
        ] : null;

        $primaryPayload = $primaryOwner ? [
            'id' => $primaryOwner->id,
            'name' => $primaryOwner->name,
            'office' => $primaryOwner->office,
        ] : null;

        if ($hasAssessment) {
            $isActor = $actedBy && (int) $actedBy->id === (int) $user->id;
            $isDrrsAa = $user->hasRole('DRRS AA') || $user->can('route epirma documents');
            $isRrosViewer = $user->hasRole('RROS') && (
                in_array((string) $request->assessment_status, ['final', 'submitted'], true)
                || filled($request->epirma_assessment_signed_at)
                || filled($request->epirma_response_letter_signed_at)
            );
            $isAaViewer = $isDrrsAa && filled($request->epirma_forwarded_to_drrs_aa_at);
            // Drafts stay locked to the creating PDRC. Final/signed docs open to RROS / DRRS AA.
            $canAccess = $isPrivileged || $isActor || $isAaViewer || $isRrosViewer;

            return [
                'can_create' => false,
                'can_act_on_behalf' => false,
                'can_access_documents' => $canAccess,
                'is_owner' => $covers,
                'acted_by' => $actedByPayload ?: [
                    'id' => null,
                    'name' => $request->assigned_social_worker ?: 'another DRRS PDRC',
                    'office' => null,
                    'on_behalf_of' => null,
                ],
                'primary_owner' => $primaryPayload,
                'reason' => $canAccess ? null : 'acted_by_other',
                'can_forward_epirma' => ($isPrivileged || $isActor) && $request->assessment_status === 'draft',
                'can_route_epirma' => $isPrivileged || $isAaViewer,
                'viewer_role' => $isAaViewer ? 'drrs_aa' : ($isRrosViewer ? 'rros' : ($isActor ? 'pdrc' : 'other')),
            ];
        }

        if (! $request->endorsed_to_drrs) {
            return [
                'can_create' => false,
                'can_act_on_behalf' => false,
                'can_access_documents' => false,
                'is_owner' => $covers,
                'acted_by' => null,
                'primary_owner' => $primaryPayload,
                'reason' => 'not_endorsed',
            ];
        }

        // Creating a new assessment is open to any DRRS / encoder; ownership locks after first save.
        // Prefer role checks so stale Spatie permission cache cannot hide Create Assessment.
        if ($isPrivileged || $isDrrs || $user->can('encode requests')) {
            return [
                'can_create' => true,
                'can_act_on_behalf' => false,
                'can_access_documents' => false,
                'is_owner' => $covers || $isPrivileged,
                'acted_by' => null,
                'primary_owner' => $primaryPayload,
                'reason' => null,
            ];
        }

        return [
            'can_create' => false,
            'can_act_on_behalf' => false,
            'can_access_documents' => false,
            'is_owner' => false,
            'acted_by' => null,
            'primary_owner' => $primaryPayload,
            'reason' => 'not_encoder',
        ];
    }

    /**
     * @return array{provinces: array<int, string>, districts: array<int, string>, cities: array<int, string>}
     */
    public function normalizeUserAorCodes(User $user): array
    {
        $fromColumns = [
            'provinces' => $this->sanitizeCodes($user->aor_provinces ?? []),
            'districts' => $this->sanitizeCodes($user->aor_districts ?? []),
            'cities' => $this->sanitizeCodes($user->aor_cities_municipalities ?? []),
        ];

        if ($fromColumns['provinces'] !== [] || $fromColumns['districts'] !== [] || $fromColumns['cities'] !== []) {
            return $fromColumns;
        }

        $entries = $user->relationLoaded('aorEntries')
            ? $user->aorEntries
            : $user->aorEntries()->get(['level', 'psgc_code']);

        return [
            'provinces' => $this->sanitizeCodes($entries->where('level', 'province')->pluck('psgc_code')->all()),
            'districts' => $this->sanitizeCodes($entries->where('level', 'district')->pluck('psgc_code')->all()),
            'cities' => $this->sanitizeCodes($entries->where('level', 'city_municipality')->pluck('psgc_code')->all()),
        ];
    }

    /**
     * @return array{city: ?string, district: ?string, province: ?string}|null
     */
    public function resolveLocationCodes(?string $psgcCode, ?string $province = null, ?string $municipality = null): ?array
    {
        $code = is_scalar($psgcCode) ? trim((string) $psgcCode) : '';
        if ($code !== '') {
            $address = PsgcAddress::query()->where('code', $code)->first(['code', 'level', 'parent_code', 'district_code']);
            if ($address) {
                if (in_array($address->level, ['city', 'municipality', 'city_municipality'], true)) {
                    return [
                        'city' => $address->code,
                        'district' => $address->district_code ? (string) $address->district_code : null,
                        'province' => $address->parent_code ? (string) $address->parent_code : null,
                    ];
                }

                if ($address->level === 'district') {
                    return [
                        'city' => null,
                        'district' => $address->code,
                        'province' => $address->parent_code ? (string) $address->parent_code : null,
                    ];
                }

                if ($address->level === 'province') {
                    return [
                        'city' => null,
                        'district' => null,
                        'province' => $address->code,
                    ];
                }
            }
        }

        $provinceName = trim((string) $province);
        $municipalityName = trim((string) $municipality);
        if ($municipalityName === '' && $provinceName === '') {
            return null;
        }

        $city = null;
        if ($municipalityName !== '') {
            $cityQuery = PsgcAddress::query()
                ->whereIn('level', ['city', 'municipality', 'city_municipality'])
                ->where('is_active', true)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($municipalityName)]);

            if ($provinceName !== '') {
                $provinceCode = PsgcAddress::query()
                    ->where('level', 'province')
                    ->where('is_active', true)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($provinceName)])
                    ->value('code');
                if ($provinceCode) {
                    $cityQuery->where('parent_code', $provinceCode);
                }
            }

            $city = $cityQuery->first(['code', 'parent_code', 'district_code']);
        }

        if ($city) {
            return [
                'city' => $city->code,
                'district' => $city->district_code ? (string) $city->district_code : null,
                'province' => $city->parent_code ? (string) $city->parent_code : null,
            ];
        }

        if ($provinceName !== '') {
            $provinceCode = PsgcAddress::query()
                ->where('level', 'province')
                ->where('is_active', true)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($provinceName)])
                ->value('code');

            if ($provinceCode) {
                return [
                    'city' => null,
                    'district' => null,
                    'province' => (string) $provinceCode,
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    protected function sanitizeCodes(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($value) => is_scalar($value) ? trim((string) $value) : null,
            $values
        ), fn (?string $value): bool => filled($value))));
    }
}
