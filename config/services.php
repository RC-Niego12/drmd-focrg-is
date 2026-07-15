<?php

return [
    'google_sheets' => [
        'url' => env('GOOGLE_SHEETS_URL'),
        'worksheet' => env('GOOGLE_SHEETS_WORKSHEET', 'Warehouse Inventory'),
        'warehouse_master_url' => env('GOOGLE_WAREHOUSE_MASTER_URL'),
        'warehouse_master_worksheet' => env('GOOGLE_WAREHOUSE_MASTER_WORKSHEET', 'Managed Warehouses'),
        'standby_fund_url' => env('GOOGLE_STANDBY_FUND_URL', 'https://docs.google.com/spreadsheets/d/1fAKrf4Fu5DVYaT2whEYlrgZRMy7sJFFG1ZWS-HE3WgI/edit?gid=1369006359#gid=1369006359'),
        'standby_fund_cell' => env('GOOGLE_STANDBY_FUND_CELL', 'L2'),
        'district_reference_url' => env('GOOGLE_DISTRICT_REFERENCE_URL', 'https://docs.google.com/spreadsheets/d/1ZCOQYF1HPXwdQwqNHtuzoHC6L4NdAYdGhL-t6DqKxKg/edit?gid=1320368843#gid=1320368843'),
        'population_url' => env('GOOGLE_POPULATION_URL', 'https://docs.google.com/spreadsheets/d/1tm2qSQ_luMhvxuFlOLVN5TEZXONHqzq4JXjADyX11X0/edit?gid=1923148867#gid=1923148867'),
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
        'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
    ],

    'response_documents' => [
        'template' => env('RESPONSE_LETTER_TEMPLATE'),
    ],

    'epirma' => [
        'sign_url' => env('EPIRMA_SIGN_URL'),
        'base_url' => env('EPIRMA_BASE_URL'),
        'client_secret' => env('EPIRMA_CLIENT_SECRET'),
        'verify_ssl' => (bool) env('EPIRMA_VERIFY_SSL', env('APP_ENV') !== 'local'),
        'allow_insecure_ssl' => (bool) env('EPIRMA_ALLOW_INSECURE_SSL', true),
        'app_name' => env('EPIRMA_APP_NAME', env('APP_NAME', 'DRIMS')),
        // Prefer APP_URL as-is for local http:// setups. Set EPIRMA_FORCE_HTTPS_URLS=true
        // (and optionally EPIRMA_PUBLIC_APP_URL) only when e-PIRMA must load docs over HTTPS.
        'force_https_urls' => (bool) env('EPIRMA_FORCE_HTTPS_URLS', false),
        'public_app_url' => env('EPIRMA_PUBLIC_APP_URL'),
        'document_token' => env('EPIRMA_DOCUMENT_TOKEN'),
    ],

    'cc_idp' => [
        'client_id' => env('CC_OAUTH_CLIENT_ID'),
        'client_secret' => env('CC_OAUTH_SECRET'),
        'authorize_url' => env('CC_OAUTH_AUTHORIZE_URL', 'https://caraga-connect-api-staging.dswd.gov.ph/sso/login'),
        'token_url' => env('CC_OAUTH_TOKEN_URL', 'https://caraga-connect-api-staging.dswd.gov.ph/oauth/token'),
        'userinfo_url' => env('CC_OAUTH_USERINFO_URL', 'https://caraga-connect-api-staging.dswd.gov.ph/api/userinfo'),
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
