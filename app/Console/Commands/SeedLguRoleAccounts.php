<?php

namespace App\Console\Commands;

use App\Models\LguDirectoryEntry;
use App\Services\LguPersonnelAccountService;
use Illuminate\Console\Command;

class SeedLguRoleAccounts extends Command
{
    protected $signature = 'lgu:seed-role-accounts
        {--password=password : Default password for new / reset role accounts}
        {--reset-default-password : Reset password for accounts still marked as default}
        {--psgc= : Limit to one PSGC code}';

    protected $description = 'Create default LCE, LSWDO, and LDRRMO portal logins for each active LGU directory profile (e.g. mlgu-tubod-sdn-lce).';

    public function handle(LguPersonnelAccountService $accounts): int
    {
        $password = (string) $this->option('password');
        $reset = (bool) $this->option('reset-default-password');
        $psgc = filled($this->option('psgc')) ? (string) $this->option('psgc') : null;

        $created = 0;
        $linked = 0;
        $skipped = 0;

        $query = LguDirectoryEntry::query()->where('is_active', true)->orderBy('id');
        if ($psgc) {
            $query->where('psgc_code', $psgc);
        }

        $query->chunkById(50, function ($entries) use ($accounts, $password, $reset, &$created, &$linked, &$skipped): void {
            foreach ($entries as $entry) {
                $results = $accounts->ensureDefaultManagerAccounts($entry, $password, $reset);
                if ($results === []) {
                    $skipped++;
                    $this->warn("Skipped {$entry->lgu_name}: could not derive base username.");

                    continue;
                }

                foreach ($results as $result) {
                    $result['created'] ? $created++ : $linked++;
                    $this->line(sprintf(
                        '%s · %s · %s',
                        $entry->override_lgu_name ?: $entry->lgu_name,
                        strtoupper(str_replace('_', ' ', $result['role'])),
                        $result['username'],
                    ));
                }
            }
        });

        $this->info("Default LGU role accounts ready. Created: {$created}; updated/linked: {$linked}; skipped LGUs: {$skipped}.");
        $this->info('Default password: '.$password.' (users must change or retain on first login).');

        return self::SUCCESS;
    }
}
