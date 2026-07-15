<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CheckCaragaConnectSSO extends Command
{
    protected $signature = 'sso:check';

    protected $description = 'Check the configured Caraga Connect SSO authorization client.';

    public function handle(): int
    {
        $authorizeUrl = config('services.cc_idp.authorize_url');
        $clientId = config('services.cc_idp.client_id');
        $redirectUri = config('services.cc_idp.redirect_uri');

        $this->line('Caraga Connect SSO configuration');
        $this->line('Client ID: '.($clientId ?: '[missing]'));
        $this->line('Authorize URL: '.($authorizeUrl ?: '[missing]'));
        $this->line('Redirect URI: '.($redirectUri ?: '[missing]'));

        if (! $authorizeUrl || ! $clientId || ! $redirectUri) {
            $this->error('Missing SSO configuration. Check CC_OAUTH_CLIENT_ID, CC_OAUTH_AUTHORIZE_URL, and CC_OAUTH_REDIRECT_URI.');

            return self::FAILURE;
        }

        $url = $authorizeUrl.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => config('services.cc_idp.scope'),
            'state' => 'diagnostic-state',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', 'diagnostic-verifier', true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);

        $response = Http::when(! config('services.cc_idp.verify_ssl'), fn ($http) => $http->withoutVerifying())
            ->withOptions(['allow_redirects' => false])
            ->get($url);

        $body = $response->body();

        if (str_contains($body, 'SSO Access Denied') || str_contains($body, 'System Not Registered')) {
            $this->error('Caraga Connect denied this client before login.');
            $this->warn('The OAuth client is not registered/enabled for SSO, is revoked, or the redirect URI is not authorized.');
            $this->line('Ask Caraga Connect admin/RICTMS to register this exact redirect URI:');
            $this->line($redirectUri);

            return self::FAILURE;
        }

        if ($response->redirect()) {
            $this->info('Caraga Connect accepted the client and returned a redirect.');
            $this->line('Location: '.$response->header('Location'));

            return self::SUCCESS;
        }

        if ($response->ok()) {
            $this->info('Caraga Connect returned an OK response. Open /sso/login in the browser to continue testing.');

            return self::SUCCESS;
        }

        $this->error('Unexpected SSO response: HTTP '.$response->status());
        $this->line($body);

        return self::FAILURE;
    }
}
