# Caraga Connect SSO Registration

The Laravel SSO integration is already configured for OAuth 2.0 Authorization Code with PKCE.

Ask Caraga Connect / DSWD FO Caraga RICTMS to register this application as an SSO OAuth client:

Application name:

```text
Disaster Response Information Management System (DRIMS)
```

Local development redirect URI:

```text
http://127.0.0.1:8010/login-sso/callback
```

Production redirect URI:

```text
https://your-production-domain/login-sso/callback
```

After they provide the OAuth client credentials, update `.env`:

```env
CC_OAUTH_CLIENT_ID=
CC_OAUTH_SECRET=
CC_OAUTH_REDIRECT_URI=http://127.0.0.1:8010/login-sso/callback
```

Then run:

```bash
php artisan config:clear
php artisan sso:check
```

If `php artisan sso:check` still says the client is denied before login, the client is not enabled for SSO or the redirect URI does not exactly match the registered value.

The name shown on the Caraga Connect consent/sign-in screen is stored in
Caraga Connect's OAuth client registry. For client ID `158`, ask the Caraga
Connect/RICTMS administrator to change the registered application name to:

```text
Disaster Response Information Management System (DRIMS)
```

Changing the System Name library or `APP_NAME` updates this application, but
cannot alter the external Caraga Connect client record.
