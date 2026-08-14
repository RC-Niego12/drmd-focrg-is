<?php

return [
    'google_sheets' => [
        'url' => env('GOOGLE_SHEETS_URL'),
        'worksheet' => env('GOOGLE_SHEETS_WORKSHEET', 'Warehouse Inventory'),
        // Hidden tabs that are the authoritative sources of Data Entry dropdowns.
        'libraries_gid' => env('GOOGLE_SHEETS_LIBRARIES_GID', '939163773'),
        'wit_libraries_gid' => env('GOOGLE_SHEETS_WIT_LIBRARIES_GID', '1184007888'),
        'warehouse_master_url' => env('GOOGLE_WAREHOUSE_MASTER_URL'),
        'warehouse_master_worksheet' => env('GOOGLE_WAREHOUSE_MASTER_WORKSHEET', 'Managed Warehouses'),
        'standby_fund_url' => env('GOOGLE_STANDBY_FUND_URL', 'https://docs.google.com/spreadsheets/d/1fAKrf4Fu5DVYaT2whEYlrgZRMy7sJFFG1ZWS-HE3WgI/edit?gid=1369006359#gid=1369006359'),
        'standby_fund_cell' => env('GOOGLE_STANDBY_FUND_CELL', 'M2'),
        'district_reference_url' => env('GOOGLE_DISTRICT_REFERENCE_URL', 'https://docs.google.com/spreadsheets/d/1ZCOQYF1HPXwdQwqNHtuzoHC6L4NdAYdGhL-t6DqKxKg/edit?gid=1320368843#gid=1320368843'),
        'regional_directory_spreadsheet_id' => env('GOOGLE_REGIONAL_DIRECTORY_SPREADSHEET_ID', '1lab2oJ_wjFpkUVZHFM3yZ3FOi3SINh7c'),
        'ldrrmo_directory_url' => env('GOOGLE_LDRRMO_DIRECTORY_URL', 'https://docs.google.com/spreadsheets/d/1ZCOQYF1HPXwdQwqNHtuzoHC6L4NdAYdGhL-t6DqKxKg/export?format=csv&gid=781563561'),
        'population_url' => env('GOOGLE_POPULATION_URL', 'https://docs.google.com/spreadsheets/d/1tm2qSQ_luMhvxuFlOLVN5TEZXONHqzq4JXjADyX11X0/edit?gid=1923148867#gid=1923148867'),
        'ris_tracking_spreadsheet_id' => env('GOOGLE_RIS_TRACKING_SPREADSHEET_ID', '1SBk2PJyS44KS4ftAsjvV9q31GdIEodanrHZOWMScsEw'),
        // RIS tracking tab (gid 1905199506): AU Name of Driver, AV Driver Contact, AW Plate, AX Received By
        'ris_tracking_gid' => env('GOOGLE_RIS_TRACKING_GID', '1905199506'),
        'ris_items_gid' => env('GOOGLE_RIS_ITEMS_GID', '482002664'),
        'stf_tracking_spreadsheet_id' => env('GOOGLE_STF_TRACKING_SPREADSHEET_ID', '1SBk2PJyS44KS4ftAsjvV9q31GdIEodanrHZOWMScsEw'),
        'stf_tracking_gid' => env('GOOGLE_STF_TRACKING_GID', '1367120220'),
        'stf_items_gid' => env('GOOGLE_STF_ITEMS_GID', '1927904915'),
        'connect_timeout' => (int) env('GOOGLE_SHEETS_CONNECT_TIMEOUT', 15),
        'request_timeout' => (int) env('GOOGLE_SHEETS_REQUEST_TIMEOUT', 60),
        'retry_attempts' => (int) env('GOOGLE_SHEETS_RETRY_ATTEMPTS', 2),
    ],

    'psgc' => [
        'base_url' => env('PSGC_API_BASE_URL', 'https://psgc.cloud/api'),
        'publication_url' => env('PSGC_PUBLICATION_URL', 'https://psa.gov.ph/system/files/scd/PSGC-1Q-2026-Publication-Datafile.xlsx'),
        'publication_name' => env('PSGC_PUBLICATION_NAME', 'PSGC 1Q 2026 Publication Datafile'),
        'allow_api_fallback' => (bool) env('PSGC_ALLOW_API_FALLBACK', false),
    ],

    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
        'model' => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
        'vision_model' => env('GROQ_VISION_MODEL', 'qwen/qwen3.6-27b'),
        'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
    ],

    'response_documents' => [
        'template' => env('RESPONSE_LETTER_TEMPLATE'),
    ],

    // https://caraga-connect-dev.dswd.gov.ph/docs/epirma-php
    // Docs handoff: /microservice/document-routing + poll /api/latest-document-base-path/{uuid}.
    // MyPortal prod reference uses /microservice/documents on caraga.epirma.dswd.gov.ph;
    // tracking still keys off external_document_uuid either way.
    'epirma' => [
        // Optional legacy handoff only when microservice credentials are missing.
        'sign_url' => env('EPIRMA_SIGN_URL'),
        // Docs sample:
        'base_url' => env('EPIRMA_BASE_URL', 'https://caraga-epirma-staging.dswd.gov.ph'),
        'client_secret' => env('EPIRMA_CLIENT_SECRET'),
        // Staff swagger: POST /api/v1/staff/epirma/register-client
        'register_path' => env('EPIRMA_REGISTER_PATH', '/api/v1/staff/epirma/register-client'),
        'register_base_url' => env('EPIRMA_REGISTER_BASE_URL', 'https://caraga-connect-dev.dswd.gov.ph'),
        // Staff Connect host for forwarded-documents (defaults to register_base_url).
        'connect_base_url' => env('EPIRMA_CONNECT_BASE_URL', env('EPIRMA_REGISTER_BASE_URL', 'https://caraga-connect-dev.dswd.gov.ph')),
        'forwarded_documents_path' => env('EPIRMA_FORWARDED_DOCUMENTS_PATH', '/api/v1/staff/epirma/forwarded-documents'),
        // Optional static staff Bearer for server-side Connect staff APIs. Falls back to
        // MYPORTAL_ACCESS_TOKEN / MYPORTAL_USERNAME+PASSWORD login when empty.
        'connect_bearer' => env('EPIRMA_CONNECT_BEARER'),
        'connect_csrf_token' => env('EPIRMA_CONNECT_CSRF_TOKEN'),
        // Prefer this Connect username for forwarded-documents (e.g. custodian rlongue).
        // When empty, uses initiating AA username / email local-part / MYPORTAL_USERNAME.
        'forwarded_documents_username' => env('EPIRMA_FORWARDED_DOCUMENTS_USERNAME'),
        // Optional staff login URL for Connect (defaults to MYPORTAL_LOGIN_URL).
        // Must match EPIRMA_CONNECT_BASE_URL host (connect-dev vs connect prod).
        'connect_login_url' => env('EPIRMA_CONNECT_LOGIN_URL', env('MYPORTAL_LOGIN_URL')),
        // Employee id_number used for build-authorize JWT when downloading public-secure-file PDFs.
        // Falls back to initiating AA / signer id_numbers from the document.
        'download_id_number' => env('EPIRMA_DOWNLOAD_ID_NUMBER'),
        'verify_ssl' => (bool) env('EPIRMA_VERIFY_SSL', env('APP_ENV') !== 'local'),
        // Docs sample uses withOptions(['verify' => false]).
        'allow_insecure_ssl' => (bool) env('EPIRMA_ALLOW_INSECURE_SSL', true),
        // Must match the client "name" from register-client (keep short; do not use spaced APP_NAME).
        'app_name' => env('EPIRMA_APP_NAME', 'DROMIS'),
        'force_https_urls' => (bool) env('EPIRMA_FORCE_HTTPS_URLS', false),
        'public_app_url' => env('EPIRMA_PUBLIC_APP_URL'),
        // Public-secure-file ?token= (may be base64:…). Not APP_KEY; not Connect CSRF.
        'document_token' => env('EPIRMA_DOCUMENT_TOKEN'),
        // Comma-separated alternate hosts to retry when the remote URL host fails DNS
        // (e.g. "caraga-epirma.dswd.gov.ph"). Same path/query is preserved.
        'host_fallbacks' => array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', (string) env('EPIRMA_HOST_FALLBACKS', ''))
        ))),
        // CURLOPT_RESOLVE map when a hostname is NXDOMAIN but the IP is known:
        // "caraga-epirma-dev.dswd.gov.ph=203.0.113.10;other.host=203.0.113.11"
        'resolve_map' => (static function (): array {
            $raw = trim((string) env('EPIRMA_RESOLVE_MAP', ''));
            if ($raw === '') {
                return [];
            }
            $map = [];
            foreach (preg_split('/[;,]+/', $raw) ?: [] as $pair) {
                $pair = trim($pair);
                if ($pair === '' || ! str_contains($pair, '=')) {
                    continue;
                }
                [$host, $ip] = array_map('trim', explode('=', $pair, 2));
                $host = strtolower($host);
                if ($host !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                    $map[$host] = $ip;
                }
            }

            return $map;
        })(),
        'local_bypass' => (bool) env('EPIRMA_LOCAL_BYPASS', false),
    ],

    'cc_idp' => [
        'client_id' => env('CC_OAUTH_CLIENT_ID'),
        'client_secret' => env('CC_OAUTH_SECRET'),
        'authorize_url' => env('CC_OAUTH_AUTHORIZE_URL', 'https://caraga-connect-api-staging.dswd.gov.ph/sso/login'),
        'token_url' => env('CC_OAUTH_TOKEN_URL', 'https://caraga-connect-api-staging.dswd.gov.ph/oauth/token'),
        'userinfo_url' => env('CC_OAUTH_USERINFO_URL', 'https://caraga-connect-api-staging.dswd.gov.ph/api/userinfo'),
        'myportal_login_url' => env('MYPORTAL_LOGIN_URL', 'https://caraga-connect.dswd.gov.ph/api/v1/staff/login'),
        'myportal_me_details_url' => env('MYPORTAL_ME_DETAILS_URL', 'https://caraga-connect.dswd.gov.ph/api/v1/staff/portal/me/details'),
        'myportal_employee_details_url' => env('MYPORTAL_EMPLOYEE_DETAILS_URL', rtrim(env('MYPORTAL_ME_DETAILS_URL', 'https://caraga-connect.dswd.gov.ph/api/v1/staff/portal/me/details'), '/').'/{id_number}'),
        'myportal_employee_search_url' => env('MYPORTAL_EMPLOYEE_SEARCH_URL', str_replace('/portal/me/details', '/portal/employee/search', env('MYPORTAL_ME_DETAILS_URL', 'https://caraga-connect.dswd.gov.ph/api/v1/staff/portal/me/details'))),
        'myportal_access_token' => env('MYPORTAL_EMPLOYEE_DIRECTORY_TOKEN', env('MYPORTAL_ACCESS_TOKEN')),
        'myportal_username' => env('MYPORTAL_USERNAME'),
        'myportal_password' => env('MYPORTAL_PASSWORD'),
        'myportal_token_cache_seconds' => (int) env('MYPORTAL_TOKEN_CACHE_SECONDS', 3300),
        'myportal_trusted_media_hosts' => env('MYPORTAL_TRUSTED_MEDIA_HOSTS', 'caraga-portal.dswd.gov.ph,caraga-connect-dev.dswd.gov.ph'),
        'logout_url' => env('CC_OAUTH_LOGOUT_URL', 'https://caraga-connect-api-staging.dswd.gov.ph/api/sso/logout'),
        'logout_all_url' => env('CC_OAUTH_LOGOUT_ALL_URL', 'https://caraga-connect-api-staging.dswd.gov.ph/api/sso/logout-all'),
        'redirect_uri' => env('CC_OAUTH_REDIRECT_URI'),
        'scope' => env('CC_OAUTH_SCOPE', 'openid profile email'),
        'verify_ssl' => (bool) env('CC_OAUTH_VERIFY_SSL', false),
        'default_role' => env('CC_SSO_DEFAULT_ROLE', 'guest'),
        'default_office' => env('CC_SSO_DEFAULT_OFFICE', 'DRRS'),
        'bypass_mfa' => (bool) env('CC_SSO_BYPASS_MFA', true),
    ],
];
