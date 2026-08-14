<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\EpirmaService;
use Illuminate\Console\Command;

class DiagnoseEpirma extends Command
{
    protected $signature = 'epirma:diagnose {--id-number= : Employee ID to test build-authorize}';

    protected $description = 'Diagnose e-PIRMA configuration and build-authorize connectivity';

    public function handle(EpirmaService $epirmaService): int
    {
        $baseUrl = (string) config('services.epirma.base_url');
        $appName = (string) config('services.epirma.app_name');
        $secretSet = trim((string) config('services.epirma.client_secret'), "\"'") !== '';
        $localBypass = (bool) config('services.epirma.local_bypass');

        $this->info('e-PIRMA diagnosis');
        $this->line('APP_ENV='.app()->environment());
        $this->line('APP_URL='.config('app.url'));
        $this->line('EPIRMA_BASE_URL='.$baseUrl);
        $this->line('EPIRMA_APP_NAME='.$appName);
        $this->line('EPIRMA_CLIENT_SECRET='.($secretSet ? 'set' : 'missing'));
        $this->line('EPIRMA_LOCAL_BYPASS='.($localBypass ? 'true' : 'false'));
        $this->line('configured='.($epirmaService->isConfigured() ? 'yes' : 'no'));

        if (! $epirmaService->isConfigured()) {
            $this->error('e-PIRMA is not configured. Set EPIRMA_BASE_URL and EPIRMA_CLIENT_SECRET.');

            return self::FAILURE;
        }

        $idNumber = trim((string) $this->option('id-number'));
        if ($idNumber === '') {
            $idNumber = trim((string) (User::query()->whereNotNull('id_number')->value('id_number') ?? '16-11720'));
        }

        $this->newLine();
        $this->info('Testing build-authorize for ID '.$idNumber.' ...');
        $result = $epirmaService->buildAuthorize($idNumber);
        $success = ! empty($result['success']) && ! empty($result['token']);
        $this->line('success='.($success ? 'yes' : 'no'));
        $this->line('message='.trim((string) ($result['message'] ?? '')));
        $this->line('has_token='.(! empty($result['token']) ? 'yes' : 'no'));

        if ($success) {
            $this->info('Authorize OK. Microservice document-routing handoff should work.');

            return self::SUCCESS;
        }

        $this->warn('Authorize failed. Real e-PIRMA signing will not start until this is fixed.');
        $this->line('Follow: https://caraga-connect-dev.dswd.gov.ph/docs/epirma-php');
        $this->line('1) POST /epirma/register-client with {"name":"'.$appName.'"}');
        $this->line('2) Set EPIRMA_BASE_URL=https://caraga-epirma-staging.dswd.gov.ph');
        $this->line('3) Set EPIRMA_CLIENT_SECRET=<secret from register-client>');
        $this->line('4) Set EPIRMA_APP_NAME='.$appName.' (same name used in register-client)');
        $this->line('5) php artisan config:clear');
        if ($localBypass) {
            $this->warn('EPIRMA_LOCAL_BYPASS is true. Set it to false for real signing only.');
        }

        return self::FAILURE;
    }
}
