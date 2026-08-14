<?php

namespace App\Console\Commands;

use App\Services\EpirmaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class RegisterEpirmaClient extends Command
{
    protected $signature = 'epirma:register-client
        {name? : Client application name used as app_name in document-routing}
        {--write-env : Write the returned secret into .env EPIRMA_CLIENT_SECRET}
        {--base-url= : Override EPIRMA_BASE_URL for this registration call}';

    protected $description = 'Register this app with e-PIRMA (POST /epirma/register-client) and print the client secret';

    public function handle(EpirmaService $epirmaService): int
    {
        $name = trim((string) ($this->argument('name') ?: config('services.epirma.app_name') ?: config('app.name') ?: 'DROMIS'));
        $baseUrl = trim((string) ($this->option('base-url') ?: config('services.epirma.base_url')));

        if ($baseUrl === '') {
            $this->error('EPIRMA_BASE_URL is empty. Set it to https://caraga-epirma-staging.dswd.gov.ph (or production) first.');

            return self::FAILURE;
        }

        $this->info("Registering e-PIRMA client \"{$name}\" against {$baseUrl} ...");

        $result = $epirmaService->registerClient($name, $baseUrl);
        if (! ($result['success'] ?? false)) {
            $this->error($result['message'] ?? 'Client registration failed.');
            if (! empty($result['status'])) {
                $this->line('HTTP status: '.$result['status']);
            }
            if (! empty($result['body'])) {
                $this->line('Response: '.substr((string) $result['body'], 0, 500));
            }
            $this->newLine();
            $this->warn('If the route is LDAP-gated, open the PHP guide:');
            $this->line('https://caraga-connect-dev.dswd.gov.ph/docs/epirma-php');
            $this->line('Register via Caraga Connect (LDAP), then set EPIRMA_CLIENT_SECRET.');
            $this->line('Authorize host should be: https://caraga-epirma-staging.dswd.gov.ph');

            return self::FAILURE;
        }

        $secret = (string) ($result['secret'] ?? '');
        $expiresAt = (string) ($result['expires_at'] ?? '');

        $this->info('Client registered successfully.');
        $this->line('name: '.$name);
        if ($expiresAt !== '') {
            $this->line('expires_at: '.$expiresAt);
        }
        $this->line('secret: '.$secret);

        if ($this->option('write-env')) {
            if ($secret === '') {
                $this->error('Registration succeeded but no secret was returned.');

                return self::FAILURE;
            }

            $this->writeEnvValue('EPIRMA_BASE_URL', $baseUrl);
            $this->writeEnvValue('EPIRMA_CLIENT_SECRET', $secret);
            $this->writeEnvValue('EPIRMA_APP_NAME', $name);
            $this->info('Updated .env (EPIRMA_BASE_URL, EPIRMA_CLIENT_SECRET, EPIRMA_APP_NAME). Run: php artisan config:clear');
        } else {
            $this->warn('Secret was not written to .env. Re-run with --write-env, or paste it manually.');
        }

        return self::SUCCESS;
    }

    private function writeEnvValue(string $key, string $value): void
    {
        $path = base_path('.env');
        if (! File::exists($path)) {
            throw new \RuntimeException('.env not found');
        }

        $contents = File::get($path);
        $line = $key.'='.$value;

        if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', $contents)) {
            $contents = preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $contents, 1);
        } else {
            $contents = rtrim($contents).PHP_EOL.$line.PHP_EOL;
        }

        File::put($path, $contents);
    }
}
