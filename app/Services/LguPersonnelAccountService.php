<?php

namespace App\Services;

use App\Models\LguDirectoryEntry;
use App\Models\LguDirectoryLdrrmoOfficer;
use App\Models\LguDirectoryLswdoAlternate;
use App\Models\LguDirectoryOfficial;
use App\Models\LguDirectoryStaffMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class LguPersonnelAccountService
{
    public const ROLE_LCE = 'lce';

    public const ROLE_LSWDO = 'lswd_officer';

    public const ROLE_LSWDO_ALTERNATE = 'lswd_officer_alternate';

    public const ROLE_LDRRMO = 'ldrrmo';

    public const ROLE_LDRRMO_ALTERNATE = 'ldrrmo_alternate';

    public const ROLE_DROMIC_ENCODER = 'dromic_encoder';

    public const ROLE_WAREHOUSE_FOCAL = 'warehouse_focal';

    public const ROLE_WAREHOUSE_STOREKEEPER = 'warehouse_storekeeper';

    public const ROLE_DRIVER = 'driver';

    public const DEFAULT_PASSWORD = 'password';

    public const FULL_EDITOR_ROLES = [
        self::ROLE_LCE,
        self::ROLE_LSWDO,
        self::ROLE_LSWDO_ALTERNATE,
        self::ROLE_LDRRMO,
        self::ROLE_LDRRMO_ALTERNATE,
    ];

    public const SELF_EDITOR_ROLES = [
        self::ROLE_DROMIC_ENCODER,
        self::ROLE_WAREHOUSE_FOCAL,
        self::ROLE_WAREHOUSE_STOREKEEPER,
        self::ROLE_DRIVER,
    ];

    public function __construct(
        private readonly LguAccountUsernameBuilder $usernames,
    ) {}

    /**
     * Ensure default LCE / LSWDO / LDRRMO portal accounts exist for an LGU profile.
     *
     * @return list<array{role:string,username:string,created:bool,linked:bool}>
     */
    public function ensureDefaultManagerAccounts(
        LguDirectoryEntry $directory,
        string $defaultPassword = self::DEFAULT_PASSWORD,
        bool $resetDefaultPassword = false,
    ): array {
        $base = $this->usernames->baseUsernameForDirectory($directory);
        if (! filled($base)) {
            return [];
        }

        $results = [];
        $directory->loadMissing(['officials', 'ldrrmoOfficers']);

        $results[] = $this->ensureOfficialRoleAccount(
            $directory,
            self::ROLE_LCE,
            'lce',
            $this->usernames->roleUsername($base, 'lce'),
            $defaultPassword,
            $resetDefaultPassword,
        );

        $results[] = $this->ensureOfficialRoleAccount(
            $directory,
            self::ROLE_LSWDO,
            'lswd_officer',
            $this->usernames->roleUsername($base, 'lswd_officer'),
            $defaultPassword,
            $resetDefaultPassword,
        );

        $results[] = $this->ensureLdrrmoRoleAccount(
            $directory,
            $this->usernames->roleUsername($base, 'ldrrmo'),
            $defaultPassword,
            $resetDefaultPassword,
        );

        return array_values(array_filter($results));
    }

    /**
     * Protected credential summary for Super Admin LGU access management.
     *
     * @return list<array{role:string,label:string,username:?string,email:?string,person_name:?string,password_is_default:bool,must_change_password:bool,default_password:?string,status:string}>
     */
    public function portalCredentialsForDirectory(LguDirectoryEntry $directory): array
    {
        $directory->loadMissing(['officials', 'ldrrmoOfficers', 'lswdoAlternates', 'staffMembers']);
        $base = $this->usernames->baseUsernameForDirectory($directory);

        $rows = [];

        $rows[] = [
            'role' => self::ROLE_LCE,
            'label' => 'LCE',
            'expected_username' => $base ? $this->usernames->roleUsername($base, 'lce') : null,
            'personnel' => $directory->officials->firstWhere('role', 'lce'),
        ];

        $rows[] = [
            'role' => self::ROLE_LSWDO,
            'label' => 'LSWDO',
            'expected_username' => $base ? $this->usernames->roleUsername($base, 'lswd_officer') : null,
            'personnel' => $directory->officials->firstWhere('role', 'lswd_officer'),
        ];

        foreach ($directory->lswdoAlternates as $index => $alternate) {
            $rows[] = [
                'role' => self::ROLE_LSWDO_ALTERNATE,
                'label' => 'Alternate LSWDO'.($directory->lswdoAlternates->count() > 1 ? ' '.($index + 1) : ''),
                'expected_username' => null,
                'personnel' => $alternate,
            ];
        }

        $primaryLdrrmo = $directory->ldrrmoOfficers->firstWhere('is_primary', true)
            ?? $directory->ldrrmoOfficers->first();
        if ($primaryLdrrmo) {
            $rows[] = [
                'role' => self::ROLE_LDRRMO,
                'label' => 'LDRRMO',
                'expected_username' => $base ? $this->usernames->roleUsername($base, 'ldrrmo') : null,
                'personnel' => $primaryLdrrmo,
            ];
        } else {
            $rows[] = [
                'role' => self::ROLE_LDRRMO,
                'label' => 'LDRRMO',
                'expected_username' => $base ? $this->usernames->roleUsername($base, 'ldrrmo') : null,
                'personnel' => null,
            ];
        }

        $alternateLdrrmoIndex = 0;
        foreach ($directory->ldrrmoOfficers as $officer) {
            if ($primaryLdrrmo && (int) $officer->id === (int) $primaryLdrrmo->id) {
                continue;
            }
            $alternateLdrrmoIndex++;
            $rows[] = [
                'role' => self::ROLE_LDRRMO_ALTERNATE,
                'label' => 'Alternate LDRRMO'.($alternateLdrrmoIndex > 1 || $directory->ldrrmoOfficers->count() > 2 ? ' '.$alternateLdrrmoIndex : ''),
                'expected_username' => null,
                'personnel' => $officer,
            ];
        }

        $staffCounters = [
            LguDirectoryStaffMember::TYPE_DROMIC_ENCODER => 0,
            LguDirectoryStaffMember::TYPE_WAREHOUSE_FOCAL => 0,
            LguDirectoryStaffMember::TYPE_WAREHOUSE_STOREKEEPER => 0,
            LguDirectoryStaffMember::TYPE_DRIVER => 0,
        ];
        $staffLabels = [
            LguDirectoryStaffMember::TYPE_DROMIC_ENCODER => 'DROMIC / SitReport Encoder',
            LguDirectoryStaffMember::TYPE_WAREHOUSE_FOCAL => 'Warehouse Focal',
            LguDirectoryStaffMember::TYPE_WAREHOUSE_STOREKEEPER => 'Warehouse Storekeeper',
            LguDirectoryStaffMember::TYPE_DRIVER => 'Driver',
        ];

        foreach ($directory->staffMembers as $member) {
            $type = (string) $member->staff_type;
            if (! isset($staffLabels[$type])) {
                continue;
            }
            $staffCounters[$type]++;
            $count = $staffCounters[$type];
            $sameTypeTotal = $directory->staffMembers->where('staff_type', $type)->count();
            $rows[] = [
                'role' => $type,
                'label' => $staffLabels[$type].($sameTypeTotal > 1 ? ' '.$count : ''),
                'expected_username' => null,
                'personnel' => $member,
            ];
        }

        return collect($rows)
            ->map(fn (array $row): array => $this->serializePortalCredentialRow($row))
            ->groupBy(function (array $row): string {
                if (filled($row['user_id'] ?? null)) {
                    return 'user:'.$row['user_id'];
                }

                $identity = mb_strtolower(trim((string) ($row['username'] ?: $row['person_name'] ?: $row['label'])));

                return 'slot:'.$row['role'].':'.$identity;
            })
            ->map(function ($group): array {
                $sorted = $group->sortBy(fn (array $row): int => $row['status'] === 'missing' ? 1 : 0)->values();
                $primary = $sorted->first();
                $labels = $group->pluck('label')->filter()->unique()->values();
                $roles = $group->pluck('role')->filter()->unique()->values();

                return [
                    ...$primary,
                    'label' => $labels->implode(' · '),
                    'roles' => $roles->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Personnel that can be reused as warehouse focals / storekeepers (same login).
     *
     * @return list<array{key:string,user_id:?int,login_username:?string,name:string,label:string,role:string,office:?string,position:?string,id_number:?string,contact_number:?string,email:?string}>
     */
    public function linkablePersonnelForDirectory(LguDirectoryEntry $directory): array
    {
        $directory->loadMissing(['officials', 'ldrrmoOfficers', 'lswdoAlternates', 'staffMembers']);
        $options = [];

        foreach ($directory->officials as $official) {
            $name = trim((string) ($official->override_name ?: $official->name));
            if ($name === '') {
                continue;
            }
            $role = match ($official->role) {
                'lce' => self::ROLE_LCE,
                'lswd_officer' => self::ROLE_LSWDO,
                'lswd_officer_alternate' => self::ROLE_LSWDO_ALTERNATE,
                default => (string) $official->role,
            };
            $options[] = $this->linkableOption(
                'official:'.$official->id,
                $name,
                $role,
                $official->user_id,
                $official->login_username,
                null,
                $official->override_position_designation ?: $official->position_designation,
                $official->id_number,
                null,
                null,
            );
        }

        foreach ($directory->lswdoAlternates as $alternate) {
            $name = trim((string) $alternate->name);
            if ($name === '') {
                continue;
            }
            $options[] = $this->linkableOption(
                'lswdo_alternate:'.$alternate->id,
                $name,
                self::ROLE_LSWDO_ALTERNATE,
                $alternate->user_id,
                $alternate->login_username,
                null,
                $alternate->position,
                $alternate->id_number,
                $alternate->contact_number,
                $alternate->email,
            );
        }

        foreach ($directory->ldrrmoOfficers as $officer) {
            $name = trim((string) $officer->name);
            if ($name === '') {
                continue;
            }
            $role = $officer->is_primary ? self::ROLE_LDRRMO : self::ROLE_LDRRMO_ALTERNATE;
            $options[] = $this->linkableOption(
                'ldrrmo_officer:'.$officer->id,
                $name,
                $role,
                $officer->user_id,
                $officer->login_username,
                $officer->office,
                $officer->designation,
                $officer->id_number,
                $officer->mobile_number,
                $officer->email_address,
            );
        }

        foreach ($directory->staffMembers as $member) {
            $name = trim((string) $member->name);
            if ($name === '') {
                continue;
            }
            // Allow linking encoder/driver (or already-provisioned warehouse people) into another warehouse slot.
            $options[] = $this->linkableOption(
                'staff:'.$member->id,
                $name,
                (string) $member->staff_type,
                $member->user_id,
                $member->login_username,
                $member->office,
                $member->position,
                $member->id_number,
                $member->contact_number,
                $member->email,
            );
        }

        return collect($options)
            ->unique('key')
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * @return array{key:string,user_id:?int,login_username:?string,name:string,label:string,role:string,office:?string,position:?string,id_number:?string,contact_number:?string,email:?string}
     */
    private function linkableOption(
        string $key,
        string $name,
        string $role,
        mixed $userId,
        mixed $loginUsername,
        mixed $office,
        mixed $position,
        mixed $idNumber,
        mixed $contactNumber,
        mixed $email,
    ): array {
        return [
            'key' => $key,
            'user_id' => filled($userId) ? (int) $userId : null,
            'login_username' => filled($loginUsername) ? (string) $loginUsername : null,
            'name' => $name,
            'label' => $this->positionLabel($role).' — '.$name,
            'role' => $role,
            'office' => filled($office) ? (string) $office : null,
            'position' => filled($position) ? (string) $position : null,
            'id_number' => filled($idNumber) ? (string) $idNumber : null,
            'contact_number' => filled($contactNumber) ? (string) $contactNumber : null,
            'email' => filled($email) ? (string) $email : null,
        ];
    }

    /**
     * @param  array{role:string,label:string,expected_username:?string,personnel:?\Illuminate\Database\Eloquent\Model}  $row
     * @return array{role:string,label:string,username:?string,email:?string,person_name:?string,user_id:?int,password_is_default:bool,must_change_password:bool,default_password:?string,status:string}
     */
    private function serializePortalCredentialRow(array $row): array
    {
        $personnel = $row['personnel'];
        $user = $personnel?->user_id ? User::query()->find($personnel->user_id) : null;
        if (! $user && filled($row['expected_username'] ?? null)) {
            $user = User::query()->where('username', $row['expected_username'])->first();
        }

        $isDefault = (bool) ($user?->password_is_default);
        $mustChange = (bool) ($user?->must_change_password);
        $personName = null;
        if ($personnel) {
            $personName = trim((string) (
                $personnel->override_name
                ?? $personnel->name
                ?? $personnel->designation
                ?? ''
            ));
        }

        $email = $user?->email
            ?: ($personnel->email ?? null)
            ?: ($personnel->email_address ?? null);

        return [
            'role' => $row['role'],
            'label' => $row['label'],
            'username' => $user?->username ?: ($personnel?->login_username ?: ($row['expected_username'] ?? null)),
            'email' => filled($email) ? (string) $email : null,
            'person_name' => $personName !== '' ? $personName : null,
            'user_id' => $user?->id,
            'password_is_default' => $isDefault,
            'must_change_password' => $mustChange,
            'default_password' => $isDefault ? self::DEFAULT_PASSWORD : null,
            'status' => ! $user
                ? 'missing'
                : ($isDefault
                    ? ($mustChange ? 'default_pending_review' : 'default_retained')
                    : 'changed_by_user'),
        ];
    }

    /**
     * @return array{role:string,username:string,created:bool,linked:bool}|null
     */
    private function ensureOfficialRoleAccount(
        LguDirectoryEntry $directory,
        string $directoryRole,
        string $officialRole,
        string $username,
        string $defaultPassword,
        bool $resetDefaultPassword,
    ): ?array {
        $official = $directory->officials()->firstOrCreate(
            ['role' => $officialRole],
            [
                'name' => $this->defaultPersonName($directory, $directoryRole),
                'position_designation' => $this->positionLabel($directoryRole),
            ],
        );

        if (! filled($official->name) || $official->name === 'Not encoded') {
            $official->forceFill([
                'name' => $this->defaultPersonName($directory, $directoryRole),
                'position_designation' => $official->position_designation ?: $this->positionLabel($directoryRole),
            ])->save();
        }

        return $this->provisionDefaultUserForPersonnel(
            $directory,
            $official,
            $directoryRole,
            $username,
            $defaultPassword,
            $resetDefaultPassword,
            (string) ($official->override_name ?: $official->name),
        );
    }

    /**
     * @return array{role:string,username:string,created:bool,linked:bool}|null
     */
    private function ensureLdrrmoRoleAccount(
        LguDirectoryEntry $directory,
        string $username,
        string $defaultPassword,
        bool $resetDefaultPassword,
    ): ?array {
        $officer = $directory->ldrrmoOfficers()->where('is_primary', true)->first()
            ?? $directory->ldrrmoOfficers()->orderBy('sort_order')->first();

        if (! $officer) {
            $officer = $directory->ldrrmoOfficers()->create([
                'sort_order' => 0,
                'is_primary' => true,
                'office' => $directory->override_lgu_name ?: $directory->lgu_name,
                'name' => filled($directory->ldrrmo_name)
                    ? $directory->ldrrmo_name
                    : $this->defaultPersonName($directory, self::ROLE_LDRRMO),
                'designation' => $directory->ldrrmo_position ?: $this->positionLabel(self::ROLE_LDRRMO),
                'mobile_number' => $directory->ldrrmo_contact,
                'email_address' => $directory->ldrrmo_email,
                'is_locally_updated' => true,
            ]);
        } elseif (! $officer->is_primary) {
            $officer->forceFill(['is_primary' => true])->save();
        }

        return $this->provisionDefaultUserForPersonnel(
            $directory,
            $officer,
            self::ROLE_LDRRMO,
            $username,
            $defaultPassword,
            $resetDefaultPassword,
            (string) $officer->name,
        );
    }

    /**
     * @return array{role:string,username:string,created:bool,linked:bool}
     */
    private function provisionDefaultUserForPersonnel(
        LguDirectoryEntry $directory,
        Model $personnel,
        string $directoryRole,
        string $username,
        string $defaultPassword,
        bool $resetDefaultPassword,
        string $displayName,
    ): array {
        $username = $this->normalizeUsername($username);
        $created = false;

        $user = $personnel->user_id
            ? User::withTrashed()->find($personnel->user_id)
            : User::withTrashed()->where('username', $username)->first();

        if (! $user) {
            $email = $this->syntheticEmail($username);
            $this->assertEmailAvailable($email, null);
            $this->assertUsernameAvailable($username, null);

            $user = new User;
            $user->forceFill([
                'name' => $displayName !== '' ? $displayName : $this->positionLabel($directoryRole),
                'email' => $email,
                'username' => $username,
                'password' => Hash::make($defaultPassword),
                'office' => 'LGU',
                'position' => $this->positionLabel($directoryRole),
                'designation' => $this->positionLabel($directoryRole),
                'area_of_assignment' => $directory->override_lgu_name ?: $directory->lgu_name,
                'employment_status' => 'LGU Account',
                'is_active' => true,
                'access_status' => 'approved',
                'access_approved_at' => now(),
                'lgu_psgc_code' => $directory->psgc_code,
                'lgu_level' => $directory->lgu_level,
                'lgu_name' => $directory->override_lgu_name ?: $directory->lgu_name,
                'lgu_directory_role' => $directoryRole,
                'must_change_password' => true,
                'password_is_default' => true,
            ])->save();
            $created = true;
        } else {
            if ($user->trashed()) {
                $user->restore();
            }

            $payload = [
                'name' => $displayName !== '' ? $displayName : $user->name,
                'username' => $username,
                'office' => 'LGU',
                'position' => $this->positionLabel($directoryRole),
                'designation' => $this->positionLabel($directoryRole),
                'area_of_assignment' => $directory->override_lgu_name ?: $directory->lgu_name,
                'is_active' => true,
                'access_status' => 'approved',
                'access_approved_at' => $user->access_approved_at ?: now(),
                'lgu_psgc_code' => $directory->psgc_code,
                'lgu_level' => $directory->lgu_level,
                'lgu_name' => $directory->override_lgu_name ?: $directory->lgu_name,
                'lgu_directory_role' => $directoryRole,
            ];

            if ($resetDefaultPassword || $user->password_is_default || ! filled($user->password)) {
                $payload['password'] = Hash::make($defaultPassword);
                $payload['must_change_password'] = true;
                $payload['password_is_default'] = true;
            }

            $this->assertUsernameAvailable($username, $user->id);
            $user->forceFill($payload)->save();
        }

        $this->ensureLguRole($user);

        $personnel->forceFill([
            'user_id' => $user->id,
            'login_username' => $username,
        ])->save();

        return [
            'role' => $directoryRole,
            'username' => $username,
            'created' => $created,
            'linked' => true,
        ];
    }

    private function defaultPersonName(LguDirectoryEntry $directory, string $directoryRole): string
    {
        $lgu = $directory->override_lgu_name ?: $directory->lgu_name;

        return match ($directoryRole) {
            self::ROLE_LCE => 'LCE of '.$lgu,
            self::ROLE_LSWDO => 'LSWDO of '.$lgu,
            self::ROLE_LDRRMO => 'LDRRMO of '.$lgu,
            default => $this->positionLabel($directoryRole).' of '.$lgu,
        };
    }

    /**
     * @return array{user:User,created:bool,password:?string}|null
     */
    public function syncAccountForPersonnel(
        LguDirectoryEntry $directory,
        Model $personnel,
        string $directoryRole,
        array $input,
    ): ?array {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $linkUserId = filled($input['link_user_id'] ?? null) ? (int) $input['link_user_id'] : null;
        $username = $this->normalizeUsername((string) ($input['login_username'] ?? $personnel->login_username ?? ''));
        $password = filled($input['login_password'] ?? null) ? (string) $input['login_password'] : null;
        $email = $this->normalizeEmail((string) ($input['email'] ?? $input['login_email'] ?? $personnel->email ?? ''));

        if ($linkUserId) {
            $linkedUser = User::withTrashed()->find($linkUserId);
            if (! $linkedUser || ! $this->userBelongsToDirectory($linkedUser, $directory)) {
                throw ValidationException::withMessages([
                    'link_user_id' => 'The selected LGU personnel login does not belong to this LGU.',
                ]);
            }

            if ($linkedUser->trashed()) {
                $linkedUser->restore();
            }

            $username = $username !== '' ? $username : $this->normalizeUsername((string) $linkedUser->username);
            $this->attachSharedUser($linkedUser, $directory, $personnel, $directoryRole, $name, $email !== '' ? $email : (string) $linkedUser->email, $password, $username);

            return ['user' => $linkedUser->fresh(), 'created' => false, 'password' => null];
        }

        if ($username === '' && ! $personnel->user_id) {
            return null;
        }

        if ($username === '' && $personnel->user_id) {
            $existing = User::query()->find($personnel->user_id);
            if ($existing) {
                $this->attachSharedUser($existing, $directory, $personnel, $directoryRole, $name, $email ?: $existing->email, null, null);

                return ['user' => $existing, 'created' => false, 'password' => null];
            }
        }

        if ($username === '') {
            return null;
        }

        $user = $personnel->user_id
            ? User::query()->find($personnel->user_id)
            : User::withTrashed()->where('username', $username)->first();

        // Reuse an existing same-LGU login when the username already belongs to this directory.
        if (! $user) {
            $candidate = User::withTrashed()->where('username', $username)->first();
            if ($candidate && $this->userBelongsToDirectory($candidate, $directory)) {
                $user = $candidate;
            }
        }

        if ($user && $this->userBelongsToDirectory($user, $directory)) {
            if ($user->trashed()) {
                $user->restore();
            }
            $this->attachSharedUser(
                $user,
                $directory,
                $personnel,
                $directoryRole,
                $name,
                $email !== '' ? $email : ($user->email ?: $this->syntheticEmail($username)),
                $password,
                $username,
            );

            return ['user' => $user->fresh(), 'created' => false, 'password' => filled($password) ? $password : null];
        }

        $this->assertUsernameAvailable($username, $personnel->user_id ? (int) $personnel->user_id : null);

        $created = false;
        $issuedPassword = null;

        if (! $user) {
            if (! filled($password)) {
                $password = $this->generateTemporaryPassword();
                $issuedPassword = $password;
            }

            $email = $email !== '' ? $email : $this->syntheticEmail($username);
            $this->assertEmailAvailable($email, null);

            $user = new User;
            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'username' => $username,
                'password' => Hash::make($password),
                'office' => 'LGU',
                'position' => $this->positionLabel($directoryRole),
                'designation' => $this->positionLabel($directoryRole),
                'area_of_assignment' => $directory->override_lgu_name ?: $directory->lgu_name,
                'employment_status' => 'LGU Account',
                'is_active' => true,
                'access_status' => 'approved',
                'access_approved_at' => now(),
                'lgu_psgc_code' => $directory->psgc_code,
                'lgu_level' => $directory->lgu_level,
                'lgu_name' => $directory->override_lgu_name ?: $directory->lgu_name,
                'lgu_directory_role' => $directoryRole,
                'must_change_password' => $password === self::DEFAULT_PASSWORD || filled($issuedPassword),
                'password_is_default' => $password === self::DEFAULT_PASSWORD,
            ])->save();
            $created = true;
        } else {
            if ($user->trashed()) {
                $user->restore();
            }
            if (filled($password)) {
                $issuedPassword = $password;
            }
            $this->attachSharedUser(
                $user,
                $directory,
                $personnel,
                $directoryRole,
                $name,
                $email !== '' ? $email : ($user->email ?: $this->syntheticEmail($username)),
                $password,
                $username,
            );

            return ['user' => $user->fresh(), 'created' => false, 'password' => $issuedPassword];
        }

        $this->ensureLguRole($user);

        $personnel->forceFill([
            'user_id' => $user->id,
            'login_username' => $username,
        ])->save();

        if ($personnel instanceof LguDirectoryStaffMember || $personnel instanceof LguDirectoryLswdoAlternate) {
            if ($email !== '') {
                $personnel->forceFill(['email' => $email])->save();
            }
        }

        return ['user' => $user->fresh(), 'created' => $created, 'password' => $issuedPassword];
    }

    /**
     * Attach an existing LGU login to another personnel slot without creating a second account.
     */
    private function attachSharedUser(
        User $user,
        LguDirectoryEntry $directory,
        Model $personnel,
        string $directoryRole,
        string $name,
        string $email,
        ?string $password,
        ?string $username,
    ): void {
        $personnel->forceFill([
            'user_id' => $user->id,
            'login_username' => $username ?: $user->username,
        ])->save();

        if ($personnel instanceof LguDirectoryStaffMember || $personnel instanceof LguDirectoryLswdoAlternate) {
            if ($email !== '') {
                $personnel->forceFill(['email' => $email])->save();
            }
        }

        $roles = collect($this->findAllLinkedPersonnel($user->fresh()))
            ->pluck('role')
            ->push($directoryRole)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $primaryRole = $this->primaryDirectoryRole($roles);

        $this->syncUserProfile(
            $user,
            $directory,
            $primaryRole,
            $name !== '' ? $name : (string) $user->name,
            $email !== '' ? $email : (string) $user->email,
            $password,
            $username,
        );
    }

    private function userBelongsToDirectory(User $user, LguDirectoryEntry $directory): bool
    {
        if (filled($directory->psgc_code) && filled($user->lgu_psgc_code) && (string) $user->lgu_psgc_code === (string) $directory->psgc_code) {
            return true;
        }

        return collect($this->findAllLinkedPersonnel($user))
            ->contains(fn (array $link): bool => (int) ($link['entry_id'] ?? 0) === (int) $directory->id);
    }

    /**
     * @param  list<string>  $roles
     */
    public function primaryDirectoryRole(array $roles): string
    {
        foreach (self::FULL_EDITOR_ROLES as $role) {
            if (in_array($role, $roles, true)) {
                return $role;
            }
        }

        foreach (self::SELF_EDITOR_ROLES as $role) {
            if (in_array($role, $roles, true)) {
                return $role;
            }
        }

        return $roles[0] ?? 'lgu';
    }

    /**
     * @return list<array{type:string,role:string,id:int,model:Model,entry_id:int}>
     */
    public function findAllLinkedPersonnel(User $user): array
    {
        if (! $user->id) {
            return [];
        }

        $links = [];

        foreach (LguDirectoryOfficial::query()->where('user_id', $user->id)->get() as $official) {
            $role = match ($official->role) {
                'lce' => self::ROLE_LCE,
                'lswd_officer' => self::ROLE_LSWDO,
                'lswd_officer_alternate' => self::ROLE_LSWDO_ALTERNATE,
                'ldrrmo_alternate' => self::ROLE_LDRRMO_ALTERNATE,
                default => $official->role,
            };
            $links[] = [
                'type' => 'official',
                'role' => $role,
                'id' => $official->id,
                'model' => $official,
                'entry_id' => $official->lgu_directory_entry_id,
            ];
        }

        foreach (LguDirectoryLswdoAlternate::query()->where('user_id', $user->id)->get() as $alternate) {
            $links[] = [
                'type' => 'lswdo_alternate',
                'role' => self::ROLE_LSWDO_ALTERNATE,
                'id' => $alternate->id,
                'model' => $alternate,
                'entry_id' => $alternate->lgu_directory_entry_id,
            ];
        }

        foreach (LguDirectoryLdrrmoOfficer::query()->where('user_id', $user->id)->get() as $officer) {
            $links[] = [
                'type' => 'ldrrmo_officer',
                'role' => $officer->is_primary ? self::ROLE_LDRRMO : self::ROLE_LDRRMO_ALTERNATE,
                'id' => $officer->id,
                'model' => $officer,
                'entry_id' => $officer->lgu_directory_entry_id,
            ];
        }

        foreach (LguDirectoryStaffMember::query()->where('user_id', $user->id)->orderBy('id')->get() as $staff) {
            $links[] = [
                'type' => 'staff',
                'role' => $staff->staff_type,
                'id' => $staff->id,
                'model' => $staff,
                'entry_id' => $staff->lgu_directory_entry_id,
            ];
        }

        return $links;
    }

    public function findLinkedPersonnel(User $user): ?array
    {
        $links = $this->findAllLinkedPersonnel($user);
        if ($links === []) {
            return null;
        }

        foreach ($links as $link) {
            if (in_array($link['role'], self::FULL_EDITOR_ROLES, true)) {
                return $link;
            }
        }

        return $links[0];
    }

    public function userCanAccessLguPortal(User $user): bool
    {
        if ($user->hasRole('Super Admin')) {
            return true;
        }

        if (! ($user->hasRole('LGU') || filled($user->lgu_psgc_code) || filled($user->lgu_level))) {
            return false;
        }

        // Preferred: account is linked to a person on the LGU profile.
        if ($this->findLinkedPersonnel($user) !== null) {
            return true;
        }

        // Bootstrap / transition: unlinked LCE, LSWDO, LDRRMO (and alternates)
        // that soft-match the directory may still sign in so they can create
        // individual personnel usernames for everyone else.
        return $user->canEditLguProfileSoftMatch();
    }

    public function isFullProfileEditor(User $user): bool
    {
        if ($user->hasRole('Super Admin')) {
            return true;
        }

        foreach ($this->findAllLinkedPersonnel($user) as $linked) {
            if (in_array($linked['role'], self::FULL_EDITOR_ROLES, true)) {
                return true;
            }
        }

        // Soft fallback for legacy accounts still matching directory names.
        return $user->canEditLguProfileSoftMatch();
    }

    public function isSelfOnlyEditor(User $user): bool
    {
        if ($this->isFullProfileEditor($user)) {
            return false;
        }

        return collect($this->findAllLinkedPersonnel($user))->contains(
            fn (array $linked): bool => in_array($linked['role'], self::SELF_EDITOR_ROLES, true)
        );
    }

    public function canEditLguProfile(User $user): bool
    {
        return $this->isFullProfileEditor($user) || $this->isSelfOnlyEditor($user);
    }

    /**
     * @return array{scope:string,can_edit:bool,linked:?array<string,mixed>,roles:list<string>}
     */
    public function editCapabilities(User $user): array
    {
        $links = $this->findAllLinkedPersonnel($user);
        $linked = $this->findLinkedPersonnel($user);
        $full = $this->isFullProfileEditor($user);
        $self = $this->isSelfOnlyEditor($user);
        $roles = collect($links)->pluck('role')->filter()->unique()->values()->all();

        return [
            'can_edit' => $full || $self,
            'scope' => $full ? 'full' : ($self ? 'self' : 'none'),
            'roles' => $roles,
            'linked' => $linked ? [
                'type' => $linked['type'],
                'role' => $linked['role'],
                'id' => $linked['id'],
                'entry_id' => $linked['entry_id'],
            ] : null,
        ];
    }

    private function syncUserProfile(
        User $user,
        LguDirectoryEntry $directory,
        string $directoryRole,
        string $name,
        string $email,
        ?string $password,
        ?string $username = null,
    ): void {
        if ($username) {
            $this->assertUsernameAvailable($username, $user->id);
        }
        $this->assertEmailAvailable($email, $user->id);

        $payload = [
            'name' => $name,
            'email' => $email,
            'office' => 'LGU',
            'position' => $this->positionLabel($directoryRole),
            'designation' => $this->positionLabel($directoryRole),
            'area_of_assignment' => $directory->override_lgu_name ?: $directory->lgu_name,
            'is_active' => true,
            'access_status' => 'approved',
            'access_approved_at' => $user->access_approved_at ?: now(),
            'lgu_psgc_code' => $directory->psgc_code,
            'lgu_level' => $directory->lgu_level,
            'lgu_name' => $directory->override_lgu_name ?: $directory->lgu_name,
            'lgu_directory_role' => $directoryRole,
        ];
        if ($username) {
            $payload['username'] = $username;
        }
        if (filled($password)) {
            $payload['password'] = Hash::make($password);
            $payload['must_change_password'] = $password === self::DEFAULT_PASSWORD;
            $payload['password_is_default'] = $password === self::DEFAULT_PASSWORD;
        }

        $user->forceFill($payload)->save();
        $this->ensureLguRole($user);
    }

    private function ensureLguRole(User $user): void
    {
        Permission::firstOrCreate(['name' => 'submit lgu dromic requests', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'LGU', 'guard_name' => 'web']);
        $role->givePermissionTo('submit lgu dromic requests');
        $user->syncRoles([$role]);
    }

    private function assertUsernameAvailable(string $username, ?int $ignoreUserId): void
    {
        $query = User::withTrashed()->where('username', $username);
        if ($ignoreUserId) {
            $query->whereKeyNot($ignoreUserId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'login_username' => "Username \"{$username}\" is already in use.",
            ]);
        }
    }

    private function assertEmailAvailable(string $email, ?int $ignoreUserId): void
    {
        $query = User::withTrashed()->where('email', $email);
        if ($ignoreUserId) {
            $query->whereKeyNot($ignoreUserId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'email' => "Email \"{$email}\" is already in use.",
            ]);
        }
    }

    private function normalizeUsername(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->replaceMatches('/[^a-z0-9._-]+/', '')
            ->trim('.-_')
            ->toString();
    }

    private function normalizeEmail(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private function syntheticEmail(string $username): string
    {
        return $username.'@lgu.dromis.local';
    }

    private function generateTemporaryPassword(): string
    {
        return 'Lgu-'.Str::password(10);
    }

    private function positionLabel(string $role): string
    {
        return match ($role) {
            self::ROLE_LCE => 'Local Chief Executive',
            self::ROLE_LSWDO => 'LSWDO',
            self::ROLE_LSWDO_ALTERNATE => 'Alternate LSWDO',
            self::ROLE_LDRRMO => 'LDRRMO',
            self::ROLE_LDRRMO_ALTERNATE => 'Alternate LDRRMO',
            self::ROLE_DROMIC_ENCODER => 'DROMIC / SitReport Encoder',
            self::ROLE_WAREHOUSE_FOCAL => 'Warehouse Focal',
            self::ROLE_WAREHOUSE_STOREKEEPER => 'Warehouse Storekeeper',
            self::ROLE_DRIVER => 'Driver',
            default => 'LGU Personnel',
        };
    }
}
