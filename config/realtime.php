<?php

return [
    'enabled' => (bool) env('SOCKET_IO_ENABLED', false),
    'public_url' => env('SOCKET_IO_PUBLIC_URL', 'http://localhost:6001'),
    'internal_url' => env('SOCKET_IO_INTERNAL_URL', 'http://localhost:6002'),
    'secret' => env('SOCKET_IO_SECRET', env('APP_KEY')),
    'token_ttl_seconds' => (int) env('SOCKET_IO_TOKEN_TTL', 90),
    'publish_timeout_seconds' => (float) env('SOCKET_IO_PUBLISH_TIMEOUT', 1.5),
];
