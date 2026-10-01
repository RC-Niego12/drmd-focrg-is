<?php

namespace App\Http\Controllers;

use App\Models\LguDirectoryEntry;
use App\Models\LguDirectoryStaffMember;
use App\Models\OperationalLibraryValue;
use App\Services\LguPersonnelAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LguProfileController extends Controller
{
    public function __construct(
        private readonly LguPersonnelAccountService $personnelAccounts,
    ) {}

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user && ($user->hasRole('LGU') || filled($user->lgu_psgc_code) || filled($user->lgu_name)), 403);

        $directory = $this->directoryFor($user);
        abort_unless($directory, 404, 'LGU directory record was not found.');

        $capabilities = $this->personnelAccounts->editCapabilities($user);
        abort_unless(
            $capabilities['can_edit'],
            403,
            'Only linked LGU personnel can update this profile.',
        );

        if (($capabilities['scope'] ?? null) === 'self') {
            return $this->updateSelfProfile($request, $user, $directory);
        }

        return $this->updateFullProfile($request, $user, $directory);
    }

    private function updateSelfProfile(
        Request $request,
        $user,
        LguDirectoryEntry $directory,
    ): RedirectResponse {
        $linked = $this->personnelAccounts->findLinkedPersonnel($user);
        abort_unless($linked, 403, 'Your account is not linked to an LGU profile person.');

        $validated = $request->validate([
            'self_name' => ['required', 'string', 'max:255'],
            'self_position' => ['nullable', 'string', 'max:255'],
            'self_id_number' => ['nullable', 'string', 'max:80'],
            'self_contact_number' => ['nullable', 'string', 'max:500'],
            'self_email' => ['nullable', 'email', 'max:500'],
            'self_office' => ['nullable', 'string', 'max:255'],
            'login_password' => ['nullable', 'string', 'min:8', 'max:120'],
            'login_password_confirmation' => ['nullable', 'same:login_password'],
        ]);

        $personnel = $linked['model'];
        $type = $linked['type'];
        $name = trim((string) $validated['self_name']);
        $issued = [];

        if ($type === 'staff') {
            $personnel->forceFill([
                'name' => $name,
                'position' => filled($validated['self_position'] ?? null) ? trim((string) $validated['self_position']) : null,
                'id_number' => filled($validated['self_id_number'] ?? null) ? trim((string) $validated['self_id_number']) : null,
                'contact_number' => filled($validated['self_contact_number'] ?? null) ? trim((string) $validated['self_contact_number']) : null,
                'email' => filled($validated['self_email'] ?? null) ? trim((string) $validated['self_email']) : $personnel->email,
                'office' => filled($validated['self_office'] ?? null) ? trim((string) $validated['self_office']) : $personnel->office,
                'is_locally_updated' => true,
            ])->save();

            if ($personnel->staff_type === LguDirectoryStaffMember::TYPE_DRIVER) {
                $this->upsertOperationalLibraryPerson('dispatch_driver', [
                    'office' => $personnel->office,
                    'name' => $personnel->name,
                    'position' => $personnel->position,
                    'id_number' => $personnel->id_number,
                    'contact_number' => $personnel->contact_number,
                ], $directory);
            }
        } elseif ($type === 'lswdo_alternate') {
            $personnel->forceFill([
                'name' => $name,
                'position' => filled($validated['self_position'] ?? null) ? trim((string) $validated['self_position']) : null,
                'id_number' => filled($validated['self_id_number'] ?? null) ? trim((string) $validated['self_id_number']) : null,
                'contact_number' => filled($validated['self_contact_number'] ?? null) ? trim((string) $validated['self_contact_number']) : null,
                'email' => filled($validated['self_email'] ?? null) ? trim((string) $validated['self_email']) : $personnel->email,
                'is_locally_updated' => true,
            ])->save();
        } elseif ($type === 'ldrrmo_officer') {
            $personnel->forceFill([
                'name' => $name,
                'designation' => filled($validated['self_position'] ?? null) ? trim((string) $validated['self_position']) : $personnel->designation,
                'id_number' => filled($validated['self_id_number'] ?? null) ? trim((string) $validated['self_id_number']) : null,
                'mobile_number' => filled($validated['self_contact_number'] ?? null) ? trim((string) $validated['self_contact_number']) : $personnel->mobile_number,
                'email_address' => filled($validated['self_email'] ?? null) ? trim((string) $validated['self_email']) : $personnel->email_address,
                'office' => filled($validated['self_office'] ?? null) ? trim((string) $validated['self_office']) : $personnel->office,
                'is_locally_updated' => true,
            ])->save();
        } elseif ($type === 'official') {
            $personnel->forceFill([
                'name' => $name,
                'override_name' => $name,
                'position_designation' => filled($validated['self_position'] ?? null) ? trim((string) $validated['self_position']) : $personnel->position_designation,
                'override_position_designation' => filled($validated['self_position'] ?? null) ? trim((string) $validated['self_position']) : $personnel->override_position_designation,
                'id_number' => filled($validated['self_id_number'] ?? null) ? trim((string) $validated['self_id_number']) : $personnel->id_number,
            ])->save();
        }

        $sync = $this->personnelAccounts->syncAccountForPersonnel(
            $directory,
            $personnel->fresh(),
            (string) $linked['role'],
            [
                'name' => $name,
                'email' => $validated['self_email'] ?? null,
                'login_username' => $personnel->login_username,
                'login_password' => $validated['login_password'] ?? null,
            ],
        );

        if ($sync && filled($sync['password'] ?? null)) {
            $issued[] = [
                'name' => $name,
                'username' => $sync['user']->username,
                'password' => $sync['password'],
                'created' => $sync['created'],
            ];
        }

        $user->forceFill(['name' => $name])->save();

        return back()
            ->with('success', 'Your LGU profile details were updated.')
            ->with('lgu_issued_credentials', $issued);
    }

    private function updateFullProfile(Request $request, $user, LguDirectoryEntry $directory): RedirectResponse
    {
        $loginFieldRules = [
            'login_username' => ['nullable', 'string', 'max:120'],
            'login_password' => ['nullable', 'string', 'min:8', 'max:120'],
        ];

        $staffMemberRules = [
            'nullable',
            'array',
            'max:20',
        ];
        $staffRowRules = [
            '*.id' => ['nullable', 'integer'],
            '*.office' => ['nullable', 'string', 'max:255'],
            '*.name' => ['nullable', 'string', 'max:255'],
            '*.position' => ['nullable', 'string', 'max:255'],
            '*.id_number' => ['nullable', 'string', 'max:80'],
            '*.contact_number' => ['nullable', 'string', 'max:500'],
            '*.email' => ['nullable', 'email', 'max:500'],
            '*.login_username' => ['nullable', 'string', 'max:120'],
            '*.login_password' => ['nullable', 'string', 'min:8', 'max:120'],
            '*.link_user_id' => ['nullable', 'integer', 'exists:users,id'],
            '*.linked_from' => ['nullable', 'string', 'max:80'],
        ];

        $validated = $request->validate([
            'lgu_name' => ['required', 'string', 'max:255'],
            'province_name' => ['nullable', 'string', 'max:255'],
            'managed_district_name' => ['nullable', 'string', 'max:255'],
            'lce_name' => ['nullable', 'string', 'max:255'],
            'lce_position' => ['nullable', 'string', 'max:255'],
            'lce_email' => ['nullable', 'string', 'max:500'],
            'lce_phone' => ['nullable', 'string', 'max:500'],
            'lce_id_number' => ['nullable', 'string', 'max:80'],
            'lce_login_username' => ['nullable', 'string', 'max:120'],
            'lce_login_password' => ['nullable', 'string', 'min:8', 'max:120'],
            'lswd_name' => ['nullable', 'string', 'max:255'],
            'lswd_position' => ['nullable', 'string', 'max:255'],
            'lswd_email' => ['nullable', 'string', 'max:500'],
            'lswd_phone' => ['nullable', 'string', 'max:500'],
            'lswd_facebook' => ['nullable', 'string', 'max:500'],
            'lswd_id_number' => ['nullable', 'string', 'max:80'],
            'lswd_login_username' => ['nullable', 'string', 'max:120'],
            'lswd_login_password' => ['nullable', 'string', 'min:8', 'max:120'],
            'lswd_alt_name' => ['nullable', 'string', 'max:255'],
            'lswd_alt_position' => ['nullable', 'string', 'max:255'],
            'lswd_alt_email' => ['nullable', 'string', 'max:500'],
            'lswd_alt_phone' => ['nullable', 'string', 'max:500'],
            'lswd_alt_facebook' => ['nullable', 'string', 'max:500'],
            'ldrrmo_name' => ['nullable', 'string', 'max:255'],
            'ldrrmo_position' => ['nullable', 'string', 'max:255'],
            'ldrrmo_contact' => ['nullable', 'string', 'max:255'],
            'ldrrmo_email' => ['nullable', 'string', 'max:500'],
            'ldrrmo_facebook' => ['nullable', 'string', 'max:500'],
            'ldrrmo_vhf' => ['nullable', 'string', 'max:500'],
            'ldrrmo_alt_name' => ['nullable', 'string', 'max:255'],
            'ldrrmo_alt_position' => ['nullable', 'string', 'max:255'],
            'ldrrmo_alt_contact' => ['nullable', 'string', 'max:500'],
            'ldrrmo_alt_email' => ['nullable', 'string', 'max:500'],
            'ldrrmo_alt_facebook' => ['nullable', 'string', 'max:500'],
            'ldrrmo_officers' => ['nullable', 'array', 'max:10'],
            'ldrrmo_officers.*.id' => ['nullable', 'integer'],
            'ldrrmo_officers.*.office' => ['nullable', 'string', 'max:255'],
            'ldrrmo_officers.*.name' => ['nullable', 'string', 'max:255'],
            'ldrrmo_officers.*.designation' => ['nullable', 'string', 'max:255'],
            'ldrrmo_officers.*.id_number' => ['nullable', 'string', 'max:80'],
            'ldrrmo_officers.*.mobile_number' => ['nullable', 'string', 'max:500'],
            'ldrrmo_officers.*.hotline_number' => ['nullable', 'string', 'max:500'],
            'ldrrmo_officers.*.landline_number' => ['nullable', 'string', 'max:500'],
            'ldrrmo_officers.*.email_address' => ['nullable', 'string', 'max:500'],
            'ldrrmo_officers.*.alternate_email_address' => ['nullable', 'string', 'max:500'],
            'ldrrmo_officers.*.vhf_radio_frequency' => ['nullable', 'string', 'max:500'],
            'ldrrmo_officers.*.facebook' => ['nullable', 'string', 'max:500'],
            'ldrrmo_officers.*.login_username' => $loginFieldRules['login_username'],
            'ldrrmo_officers.*.login_password' => $loginFieldRules['login_password'],
            'lswdo_alternates' => ['nullable', 'array', 'max:10'],
            'lswdo_alternates.*.id' => ['nullable', 'integer'],
            'lswdo_alternates.*.name' => ['nullable', 'string', 'max:255'],
            'lswdo_alternates.*.position' => ['nullable', 'string', 'max:255'],
            'lswdo_alternates.*.contact_number' => ['nullable', 'string', 'max:500'],
            'lswdo_alternates.*.id_number' => ['nullable', 'string', 'max:80'],
            'lswdo_alternates.*.email' => ['nullable', 'email', 'max:500'],
            'lswdo_alternates.*.login_username' => $loginFieldRules['login_username'],
            'lswdo_alternates.*.login_password' => $loginFieldRules['login_password'],
            'dromic_encoders' => $staffMemberRules,
            ...collect($staffRowRules)->mapWithKeys(
                fn ($rules, $key) => ["dromic_encoders.{$key}" => $rules]
            )->all(),
            'warehouse_focals' => $staffMemberRules,
            ...collect($staffRowRules)->mapWithKeys(
                fn ($rules, $key) => ["warehouse_focals.{$key}" => $rules]
            )->all(),
            'warehouse_storekeepers' => $staffMemberRules,
            ...collect($staffRowRules)->mapWithKeys(
                fn ($rules, $key) => ["warehouse_storekeepers.{$key}" => $rules]
            )->all(),
            'drivers' => $staffMemberRules,
            ...collect($staffRowRules)->mapWithKeys(
                fn ($rules, $key) => ["drivers.{$key}" => $rules]
            )->all(),
            'logo' => ['nullable', 'image', 'max:2048'],
            'ldrrmc_logo' => ['nullable', 'image', 'max:2048'],
            'lce_photo' => ['nullable', 'image', 'max:2048'],
            'lswd_photo' => ['nullable', 'image', 'max:2048'],
            'ldrrmo_photo' => ['nullable', 'image', 'max:2048'],
        ]);

        $logoPath = $directory->lgu_logo_path;
        $ldrrmcLogoPath = $directory->ldrrmc_logo_path;

        if ($request->hasFile('logo')) {
            if ($logoPath) {
                Storage::disk('public')->delete($logoPath);
            }

            $logoPath = $request->file('logo')->store('lgu-logos', 'public');
        }
        if ($request->hasFile('ldrrmc_logo')) {
            if ($ldrrmcLogoPath) {
                Storage::disk('public')->delete($ldrrmcLogoPath);
            }

            $ldrrmcLogoPath = $request->file('ldrrmc_logo')->store('lgu-logos', 'public');
        }

        $lcePhotoPath = $this->replaceStoredImage($request, 'lce_photo', $directory->lce_photo_path, 'lgu-officials');
        $lswdPhotoPath = $this->replaceStoredImage($request, 'lswd_photo', $directory->lswd_photo_path, 'lgu-officials');
        $ldrrmoPhotoPath = $this->replaceStoredImage($request, 'ldrrmo_photo', $directory->ldrrmo_photo_path, 'lgu-officials');

        $directory->forceFill([
            'override_lgu_name' => $validated['lgu_name'],
            'managed_district_name' => ($validated['managed_district_name'] ?? null) ?: $directory->managed_district_name,
            'lce_photo_path' => $lcePhotoPath,
            'lswd_photo_path' => $lswdPhotoPath,
            'ldrrmo_photo_path' => $ldrrmoPhotoPath,
            'ldrrmo_name' => $validated['ldrrmo_name'] ?? null,
            'ldrrmo_position' => $validated['ldrrmo_position'] ?? null,
            'ldrrmo_contact' => $validated['ldrrmo_contact'] ?? null,
            'ldrrmo_email' => $validated['ldrrmo_email'] ?? null,
            'lswd_email' => $validated['lswd_email'] ?? null,
            'lswd_contact_number' => $validated['lswd_phone'] ?? null,
            'lswd_alternate_email' => $validated['lswd_alt_email'] ?? null,
            'lswd_facebook' => $validated['lswd_facebook'] ?? null,
            'lswd_alternate_name' => $validated['lswd_alt_name'] ?? null,
            'lswd_alternate_position' => $validated['lswd_alt_position'] ?? null,
            'lswd_alternate_contact_number' => $validated['lswd_alt_phone'] ?? null,
            'lgu_logo_path' => $logoPath,
            'ldrrmc_logo_path' => $ldrrmcLogoPath,
        ])->save();

        $issued = [];

        $this->syncLdrrmoOfficers($directory, $validated['ldrrmo_officers'] ?? [], $issued);
        $this->syncLswdoAlternates($directory, $validated['lswdo_alternates'] ?? [], $issued);

        $lceOfficial = $this->upsertOfficial(
            $directory,
            'lce',
            $validated['lce_name'] ?? null,
            $validated['lce_position'] ?? null,
            $validated['lce_id_number'] ?? null,
        );
        if ($lceOfficial) {
            $this->collectIssued($issued, $this->personnelAccounts->syncAccountForPersonnel(
                $directory,
                $lceOfficial,
                LguPersonnelAccountService::ROLE_LCE,
                [
                    'name' => $validated['lce_name'] ?? null,
                    'email' => $validated['lce_email'] ?? null,
                    'login_username' => $validated['lce_login_username'] ?? null,
                    'login_password' => $validated['lce_login_password'] ?? null,
                ],
            ));
        }

        $lswdOfficial = $this->upsertOfficial(
            $directory,
            'lswd_officer',
            $validated['lswd_name'] ?? null,
            $validated['lswd_position'] ?? null,
            $validated['lswd_id_number'] ?? null,
        );
        if ($lswdOfficial) {
            $this->collectIssued($issued, $this->personnelAccounts->syncAccountForPersonnel(
                $directory,
                $lswdOfficial,
                LguPersonnelAccountService::ROLE_LSWDO,
                [
                    'name' => $validated['lswd_name'] ?? null,
                    'email' => $validated['lswd_email'] ?? null,
                    'login_username' => $validated['lswd_login_username'] ?? null,
                    'login_password' => $validated['lswd_login_password'] ?? null,
                ],
            ));
        }

        $this->upsertOfficial(
            $directory,
            'lswd_officer_alternate',
            $validated['lswd_alt_name'] ?? null,
            $validated['lswd_alt_position'] ?? null,
        );
        $this->upsertOfficial(
            $directory,
            'ldrrmo_alternate',
            $validated['ldrrmo_alt_name'] ?? null,
            $validated['ldrrmo_alt_position'] ?? null,
        );
        $this->upsertContact($directory, 'lce', 'email', $validated['lce_email'] ?? null);
        $this->upsertContact($directory, 'lce', 'phone', $validated['lce_phone'] ?? null);
        $this->upsertContact($directory, 'lswd_officer', 'email', $validated['lswd_email'] ?? null);
        $this->upsertContact($directory, 'lswd_officer', 'phone', $validated['lswd_phone'] ?? null);
        $this->upsertContact($directory, 'lswd_officer', 'facebook', $validated['lswd_facebook'] ?? null);
        $this->upsertContact($directory, 'lswd_officer_alternate', 'email', $validated['lswd_alt_email'] ?? null);
        $this->upsertContact($directory, 'lswd_officer_alternate', 'phone', $validated['lswd_alt_phone'] ?? null);
        $this->upsertContact($directory, 'lswd_officer_alternate', 'facebook', $validated['lswd_alt_facebook'] ?? null);
        $this->upsertContact($directory, 'ldrrmo', 'email', $validated['ldrrmo_email'] ?? null);
        $this->upsertContact($directory, 'ldrrmo', 'contact', $validated['ldrrmo_contact'] ?? null);
        $this->upsertContact($directory, 'ldrrmo', 'facebook', $validated['ldrrmo_facebook'] ?? null);
        $this->upsertContact($directory, 'ldrrmo', 'vhf', $validated['ldrrmo_vhf'] ?? null);
        $this->upsertContact($directory, 'ldrrmo_alternate', 'email', $validated['ldrrmo_alt_email'] ?? null);
        $this->upsertContact($directory, 'ldrrmo_alternate', 'contact', $validated['ldrrmo_alt_contact'] ?? null);
        $this->upsertContact($directory, 'ldrrmo_alternate', 'facebook', $validated['ldrrmo_alt_facebook'] ?? null);

        $defaultOffice = $validated['lgu_name'] ?: ($directory->override_lgu_name ?: $directory->lgu_name);
        $encoders = $this->syncStaffMembers(
            $directory,
            LguDirectoryStaffMember::TYPE_DROMIC_ENCODER,
            $validated['dromic_encoders'] ?? [],
            $defaultOffice,
            $issued,
        );
        $this->syncStaffMembers(
            $directory,
            LguDirectoryStaffMember::TYPE_WAREHOUSE_FOCAL,
            $validated['warehouse_focals'] ?? [],
            $defaultOffice,
            $issued,
        );
        $this->syncStaffMembers(
            $directory,
            LguDirectoryStaffMember::TYPE_WAREHOUSE_STOREKEEPER,
            $validated['warehouse_storekeepers'] ?? [],
            $defaultOffice,
            $issued,
        );
        $drivers = $this->syncStaffMembers(
            $directory,
            LguDirectoryStaffMember::TYPE_DRIVER,
            $validated['drivers'] ?? [],
            $defaultOffice,
            $issued,
        );

        foreach ($drivers as $person) {
            $this->upsertOperationalLibraryPerson('dispatch_driver', $person, $directory);
        }

        $user->forceFill([
            'lgu_name' => $validated['lgu_name'],
        ])->save();

        return back()
            ->with('success', 'LGU profile updated.')
            ->with('lgu_issued_credentials', $issued);
    }

    /**
     * @param  list<array{name:string,username:string,password:string,created:bool}>  $issued
     * @param  array{user:\App\Models\User,created:bool,password:?string}|null  $result
     */
    private function collectIssued(array &$issued, ?array $result): void
    {
        if (! $result || ! filled($result['password'] ?? null)) {
            return;
        }

        $issued[] = [
            'name' => $result['user']->name,
            'username' => $result['user']->username,
            'password' => $result['password'],
            'created' => (bool) $result['created'],
        ];
    }

    private function directoryFor($user): ?LguDirectoryEntry
    {
        if ($user->lgu_psgc_code) {
            $directory = LguDirectoryEntry::query()
                ->where('psgc_code', $user->lgu_psgc_code)
                ->first();

            if ($directory) {
                return $directory;
            }
        }

        if ($user->lgu_name) {
            return LguDirectoryEntry::query()
                ->where(function ($query) use ($user): void {
                    $query->where('lgu_name', $user->lgu_name)
                        ->orWhere('override_lgu_name', $user->lgu_name);
                })
                ->first();
        }

        return null;
    }

    private function upsertOfficial(
        LguDirectoryEntry $directory,
        string $role,
        ?string $name,
        ?string $position,
        ?string $idNumber = null,
    ) {
        if (! filled($name) && ! filled($position) && ! filled($idNumber)) {
            return null;
        }

        return $directory->officials()->updateOrCreate(
            ['role' => $role],
            [
                'name' => $name ?: 'Not encoded',
                'position_designation' => $position,
                'override_name' => $name,
                'override_position_designation' => $position,
                'id_number' => filled($idNumber) ? trim((string) $idNumber) : null,
            ],
        );
    }

    private function upsertContact(LguDirectoryEntry $directory, string $owner, string $type, ?string $value): void
    {
        if (! filled($value)) {
            return;
        }

        $directory->contacts()->updateOrCreate(
            ['owner_role' => $owner, 'contact_type' => $type],
            ['value' => $value, 'override_value' => $value],
        );
    }

    private function replaceStoredImage(Request $request, string $field, ?string $currentPath, string $directory): ?string
    {
        if (! $request->hasFile($field)) {
            return $currentPath;
        }

        if ($currentPath) {
            Storage::disk('public')->delete($currentPath);
        }

        return $request->file($field)->store($directory, 'public');
    }

    /**
     * @param  list<array{name:string,username:string,password:string,created:bool}>  $issued
     */
    private function syncLdrrmoOfficers(LguDirectoryEntry $directory, array $officers, array &$issued): void
    {
        foreach (array_values($officers) as $index => $officer) {
            $values = collect($officer)->only([
                'office',
                'name',
                'designation',
                'id_number',
                'mobile_number',
                'hotline_number',
                'landline_number',
                'email_address',
                'alternate_email_address',
                'vhf_radio_frequency',
                'facebook',
            ])->all() + [
                'sort_order' => $index,
                'is_primary' => $index === 0,
                'is_locally_updated' => true,
            ];

            if (array_key_exists('id_number', $values)) {
                $values['id_number'] = filled($values['id_number'] ?? null)
                    ? trim((string) $values['id_number'])
                    : null;
            }

            $row = filled($officer['id'] ?? null)
                ? $directory->ldrrmoOfficers()->whereKey($officer['id'])->first()
                : null;

            if ($row) {
                $row->update($values);
            } else {
                $row = $directory->ldrrmoOfficers()->create($values);
            }
            $row = $row->fresh();

            if (filled($officer['name'] ?? null)) {
                $this->collectIssued($issued, $this->personnelAccounts->syncAccountForPersonnel(
                    $directory,
                    $row,
                    $index === 0
                        ? LguPersonnelAccountService::ROLE_LDRRMO
                        : LguPersonnelAccountService::ROLE_LDRRMO_ALTERNATE,
                    [
                        'name' => $officer['name'] ?? null,
                        'email' => $officer['email_address'] ?? null,
                        'login_username' => $officer['login_username'] ?? null,
                        'login_password' => $officer['login_password'] ?? null,
                    ],
                ));
            }
        }

        $primary = $officers[0] ?? null;
        if ($primary) {
            $contact = collect([
                $primary['mobile_number'] ?? null,
                $primary['hotline_number'] ?? null,
                $primary['landline_number'] ?? null,
            ])->filter(fn ($value) => filled($value))->implode(' / ');

            $directory->forceFill([
                'ldrrmo_name' => $primary['name'] ?? null,
                'ldrrmo_position' => $primary['designation'] ?? null,
                'ldrrmo_contact' => $contact ?: null,
                'ldrrmo_email' => $primary['email_address'] ?? null,
            ])->save();
            $this->upsertContact($directory, 'ldrrmo', 'facebook', $primary['facebook'] ?? null);
            $this->upsertContact($directory, 'ldrrmo', 'vhf', $primary['vhf_radio_frequency'] ?? null);
        }
    }

    /**
     * @param  list<array{name:string,username:string,password:string,created:bool}>  $issued
     */
    private function syncLswdoAlternates(LguDirectoryEntry $directory, array $alternates, array &$issued): void
    {
        foreach (array_values($alternates) as $index => $alternate) {
            $values = collect($alternate)->only(['name', 'position', 'contact_number', 'id_number', 'email'])->all() + [
                'sort_order' => $index,
                'is_locally_updated' => true,
            ];
            if (array_key_exists('id_number', $values)) {
                $values['id_number'] = filled($values['id_number'] ?? null)
                    ? trim((string) $values['id_number'])
                    : null;
            }
            $row = filled($alternate['id'] ?? null)
                ? $directory->lswdoAlternates()->whereKey($alternate['id'])->first()
                : null;
            if ($row) {
                $row->update($values);
            } else {
                $row = $directory->lswdoAlternates()->create($values);
            }
            $row = $row->fresh();

            if (filled($alternate['name'] ?? null)) {
                $this->collectIssued($issued, $this->personnelAccounts->syncAccountForPersonnel(
                    $directory,
                    $row,
                    LguPersonnelAccountService::ROLE_LSWDO_ALTERNATE,
                    [
                        'name' => $alternate['name'] ?? null,
                        'email' => $alternate['email'] ?? null,
                        'login_username' => $alternate['login_username'] ?? null,
                        'login_password' => $alternate['login_password'] ?? null,
                    ],
                ));
            }
        }

        if ($primary = $alternates[0] ?? null) {
            $directory->forceFill([
                'lswd_alternate_name' => $primary['name'] ?? null,
                'lswd_alternate_position' => $primary['position'] ?? null,
                'lswd_alternate_contact_number' => $primary['contact_number'] ?? null,
            ])->save();
            $this->upsertOfficial(
                $directory,
                'lswd_officer_alternate',
                $primary['name'] ?? null,
                $primary['position'] ?? null,
                $primary['id_number'] ?? null,
            );
            $this->upsertContact($directory, 'lswd_officer_alternate', 'phone', $primary['contact_number'] ?? null);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{name:string,username:string,password:string,created:bool}>  $issued
     * @return list<array{office:?string,name:string,position:?string,id_number:?string,contact_number:?string}>
     */
    private function syncStaffMembers(
        LguDirectoryEntry $directory,
        string $staffType,
        array $rows,
        ?string $defaultOffice = null,
        array &$issued = [],
    ): array {
        $keptIds = [];
        $saved = [];

        foreach (array_values($rows) as $index => $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $office = trim((string) ($row['office'] ?? ''));
            if ($office === '' && filled($defaultOffice)) {
                $office = trim((string) $defaultOffice);
            }

            $values = [
                'staff_type' => $staffType,
                'sort_order' => $index,
                'office' => $office !== '' ? $office : null,
                'name' => $name,
                'position' => filled($row['position'] ?? null) ? trim((string) $row['position']) : null,
                'id_number' => filled($row['id_number'] ?? null) ? trim((string) $row['id_number']) : null,
                'contact_number' => filled($row['contact_number'] ?? null) ? trim((string) $row['contact_number']) : null,
                'email' => filled($row['email'] ?? null) ? trim((string) $row['email']) : null,
                'is_locally_updated' => true,
            ];

            $existing = filled($row['id'] ?? null)
                ? $directory->staffMembers()
                    ->where('staff_type', $staffType)
                    ->whereKey($row['id'])
                    ->first()
                : null;

            if ($existing) {
                $existing->update($values);
                $member = $existing->fresh();
                $keptIds[] = $member->id;
            } else {
                $member = $directory->staffMembers()->create($values);
                $keptIds[] = $member->id;
            }

            $this->collectIssued($issued, $this->personnelAccounts->syncAccountForPersonnel(
                $directory,
                $member,
                $staffType,
                [
                    'name' => $name,
                    'email' => $row['email'] ?? null,
                    'login_username' => $row['login_username'] ?? null,
                    'login_password' => $row['login_password'] ?? null,
                    'link_user_id' => $row['link_user_id'] ?? null,
                ],
            ));

            $saved[] = [
                'office' => $values['office'],
                'name' => $values['name'],
                'position' => $values['position'],
                'id_number' => $values['id_number'],
                'contact_number' => $values['contact_number'],
            ];
        }

        $query = $directory->staffMembers()->where('staff_type', $staffType);
        if ($keptIds === []) {
            $query->delete();
        } else {
            $query->whereNotIn('id', $keptIds)->delete();
        }

        return $saved;
    }

    /**
     * @param  array{office:?string,name:string,position:?string,id_number:?string,contact_number:?string}  $person
     */
    private function upsertOperationalLibraryPerson(
        string $libraryType,
        array $person,
        LguDirectoryEntry $directory,
    ): void {
        $name = trim((string) ($person['name'] ?? ''));
        if ($name === '') {
            return;
        }

        $normalized = OperationalLibraryValue::normalizeLibraryName($name);
        $existing = OperationalLibraryValue::query()
            ->where('library_type', $libraryType)
            ->get()
            ->first(fn (OperationalLibraryValue $row): bool => OperationalLibraryValue::normalizeLibraryName($row->value) === $normalized);

        $metadata = array_filter([
            'office' => $person['office'] ?? null,
            'position' => $person['position'] ?? null,
            'id_number' => $person['id_number'] ?? null,
            'contact_number' => $person['contact_number'] ?? null,
            'lgu_psgc_code' => $directory->psgc_code,
            'lgu_name' => $directory->override_lgu_name ?: $directory->lgu_name,
            'source' => 'lgu_profile',
        ], fn ($value) => filled($value));

        if ($existing) {
            $merged = array_filter([
                ...(is_array($existing->metadata) ? $existing->metadata : []),
                ...$metadata,
            ], fn ($value) => filled($value));

            $existing->forceFill([
                'value' => $name,
                'metadata' => $merged === [] ? null : $merged,
                'is_active' => true,
            ])->save();

            return;
        }

        OperationalLibraryValue::create([
            'library_type' => $libraryType,
            'value' => $name,
            'context' => 'all',
            'metadata' => $metadata === [] ? null : $metadata,
            'is_active' => true,
        ]);
    }
}
