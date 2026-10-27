<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (M12)
|--------------------------------------------------------------------------
|
| The studio SPA authenticates with a bearer token, not cookies, so
| credentials are never shared cross-origin. Only the exact origins listed
| in CORS_ALLOWED_ORIGINS (comma-separated scheme://host[:port]) may call
| the API from a browser. A "*" entry is ignored on purpose.
|
*/

$origins = array_values(array_filter(
    array_map(
        static fn (string $origin): string => rtrim(trim($origin), '/'),
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173')),
    ),
    static fn (string $origin): bool => $origin !== '' && $origin !== '*',
));

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'X-Requested-With'],

    'exposed_headers' => ['Content-Disposition', 'Retry-After'],

    'max_age' => 3600,

    'supports_credentials' => false,

];
