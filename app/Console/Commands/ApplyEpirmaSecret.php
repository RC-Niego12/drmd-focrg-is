<?php

namespace App\Console\Commands;

use App\Services\EpirmaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ApplyEpirmaSecret extends Command
{
    protected $signature = 'epirma:apply-secret
        {secret : Hex secret from register-client data.data.secret}
        {--name= : Client name (defaults to current EPIRMA_APP_NAME)}
        {--base-url= : e-PIRMA host (defaults to staging)}
        {--id-number=16-11720 : Employee ID for authorize smoke test}';

    protected $description = 'Write EPIRMA_CLIENT_SECRET / APP_NAME to .env and verify build-authorize';

    public function handle(): int
    {
        $secret = trim((string) $this->argument('secret'), " \t\n\r\0\x0B\"'");
        $name = trim((string) ($this->option('name') ?: config('services.epirma.app_name') ?: 'DROMIS'));
        $baseUrl = rtrim(trim((string) ($this->option('base-url') ?: config('services.epirma.base_url') ?: 'https://caraga-epirma-staging.dswd.gov.ph')), '/');
        $idNumber = trim((string) $this->option('id-number'));

        if ($secret === '' || ! ctype_xdigit($secret)) {
            $this->error('Secret must be a hex string copied from register-client.');

            return self::FAILURE;
        }

        $this->writeEnvValue('EPIRMA_BASE_URL', $baseUrl);
        $this->writeEnvValue('EPIRMA_CLIENT_SECRET', $secret);
        $this->writeEnvValue('EPIRMA_APP_NAME', $name);
        $this->writeEnvValue('EPIRMA_LOCAL_BYPASS', 'false');
        $this->writeEnvValue('EPIRMA_ALLOW_INSECURE_SSL', 'true');
        $this->writeEnvValue('EPIRMA_REGISTER_BASE_URL', 'https://caraga-connect-dev.dswd.gov.ph');
        $this->writeEnvValue('EPIRMA_REGISTER_PATH', '/api/v1/staff/epirma/register-client');

        $this->call('config:clear');

        $service = new EpirmaService([
            'base_url' => $baseUrl,
            'client_secret' => $secret,
            'verify_ssl' => false,
        ]);
        $result = $service->buildAuthorize($idNumber);
        $ok = ! empty($result['success']) && ! empty($result['token']);

        $this->info('Wrote EPIRMA_* values to .env');
        $this->line('EPIRMA_BASE_URL='.$baseUrl);
        $this->line('EPIRMA_APP_NAME='.$name);
        $this->line('secret_len='.strlen($secret));
        $this->line('authorize_success='.($ok ? 'yes' : 'no'));
        $this->line('authorize_message='.trim((string) ($result['message'] ?? '')));

        if (! $ok) {
            $this->error('Staging still rejected this secret. Re-copy data.data.secret from Swagger (not token).');

            return self::FAILURE;
        }

        $this->info('Authorize OK. Try Sign with e-PIRMA again.');

        return self::SUCCESS;
    }

    private function writeEnvValue(string $key, string $value): void
    {
        $path = base_path('.env');
        if (! File::exists($path)) {
            throw new \RuntimeException('.env not found');
        }

        $contents = File::get($path);
        $needsQuotes = (bool) preg_match('/[\s#\'"]/', $value);
        $line = $needsQuotes
            ? $key.'="'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"'
            : $key.'='.$value;

        if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', $contents)) {
            $contents = preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $contents, 1);
        } else {
            $contents = rtrim($contents).PHP_EOL.$line.PHP_EOL;
        }

        File::put($path, $contents);
    }
}
