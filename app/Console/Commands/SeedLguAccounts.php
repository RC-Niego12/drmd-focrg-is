<?php

namespace App\Console\Commands;

use App\Models\PsgcAddress;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class SeedLguAccounts extends Command
{
    protected $signature = 'lgu:seed-accounts {--password=password : Password to set for all generated LGU accounts}';

    protected $description = 'Create simple LGU user accounts for each active province, city, and municipality in the PSGC table.';

    private const PROVINCE_ABBREVIATIONS = [
        '1600200000' => 'adn',
        '1600300000' => 'ads',
        '1608500000' => 'pdi',
        '1606700000' => 'sdn',
        '1606800000' => 'sds',
    ];

    private const SPECIAL_CITY_PROVINCE_CODES = [
        // Butuan City is stored as a Caraga HUC in PSGC, but DROMIS groups it
        // operationally with Agusan del Norte for LGU account labels.
        '1630400000' => '1600200000',
    ];

    public function handle(): int
    {
        Permission::firstOrCreate(['name' => 'submit lgu dromic requests', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'LGU', 'guard_name' => 'web']);
        $role->syncPermissions(['submit lgu dromic requests']);

        $password = (string) $this->option('password');
        $created = 0;
        $updated = 0;

        PsgcAddress::query()
            ->where('is_active', true)
            ->whereIn('level', ['province', 'city', 'municipality', 'city_municipality'])
            ->orderBy('level')
            ->orderBy('name')
            ->chunkById(100, function ($addresses) use ($password, $role, &$created, &$updated): void {
                foreach ($addresses as $address) {
                    $username = $this->usernameFor($address);
                    $email = $username.'@dromis.test';
                    $displayName = $this->displayNameFor($address);
                    $displayPrefix = $this->displayPrefixFor($address);

                    $user = User::withTrashed()
                        ->where('lgu_psgc_code', $address->code)
                        ->first()
                        ?? User::withTrashed()->firstOrNew(['email' => $email]);
                    $exists = $user->exists;

                    if ($user->trashed()) {
                        $user->restore();
                    }

                    $user->forceFill([
                        'name' => "{$displayPrefix} {$displayName}",
                        'email' => $email,
                        'username' => $username,
                        'password' => Hash::make($password),
                        'office' => 'LGU',
                        'position' => 'LGU DROMIC Encoder',
                        'designation' => 'LGU Focal Person',
                        'area_of_assignment' => $displayName,
                        'employment_status' => 'LGU Account',
                        'is_active' => true,
                        'access_status' => 'approved',
                        'access_approved_at' => $user->access_approved_at ?: now(),
                        'lgu_psgc_code' => $address->code,
                        'lgu_level' => $displayPrefix,
                        'lgu_name' => $displayName,
                    ])->save();

                    $user->syncRoles([$role]);
                    $exists ? $updated++ : $created++;
                }
            });

        $this->info("LGU accounts ready. Created: {$created}; updated/restored: {$updated}; password set for all LGU accounts.");

        return self::SUCCESS;
    }

    private function usernameFor(PsgcAddress $address): string
    {
        $provinceCode = $this->provinceCodeFor($address);
        $province = self::PROVINCE_ABBREVIATIONS[(string) $provinceCode] ?? Str::of((string) $provinceCode)->substr(-4)->lower();

        if ($address->level === 'province') {
            return 'plgu-'.$province;
        }

        $name = Str::of($this->localGovernmentName($address))
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->toString();

        $prefix = str_contains(strtolower((string) $address->type), 'city') ? 'clgu' : 'mlgu';

        return "{$prefix}-{$name}-{$province}";
    }

    private function displayNameFor(PsgcAddress $address): string
    {
        if ($address->level === 'province') {
            return $address->name;
        }

        $provinceName = PsgcAddress::query()
            ->where('code', $this->provinceCodeFor($address))
            ->value('name');

        $localName = $this->localGovernmentName($address);

        return filled($provinceName)
            ? "{$localName}, {$provinceName}"
            : $localName;
    }

    private function displayPrefixFor(PsgcAddress $address): string
    {
        if ($address->level === 'province') {
            return 'PLGU';
        }

        return str_contains(strtolower((string) $address->type), 'city')
            ? 'CLGU'
            : 'MLGU';
    }

    private function provinceCodeFor(PsgcAddress $address): string
    {
        if ($address->level === 'province') {
            return (string) $address->code;
        }

        return self::SPECIAL_CITY_PROVINCE_CODES[(string) $address->code]
            ?? (string) $address->parent_code;
    }

    private function localGovernmentName(PsgcAddress $address): string
    {
        $name = trim((string) $address->name);

        if (preg_match('/^City of\s+(.+)$/i', $name, $matches)) {
            return trim($matches[1]).' City';
        }

        if (preg_match('/^Municipality of\s+(.+)$/i', $name, $matches)) {
            return trim($matches[1]);
        }

        return $name;
    }
}
