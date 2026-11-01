<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Watchpost Connection
    |--------------------------------------------------------------------------
    |
    | The Watchpost address and this website's ingest token. The token is shown
    | once in Watchpost when the website is added (or when a new one is made).
    |
    */

    'url' => env('WATCHPOST_URL'),

    'token' => env('WATCHPOST_TOKEN'),

    'enabled' => env('WATCHPOST_ENABLED', true),

    // Only these environments report; local development stays quiet.
    'environments' => array_values(array_filter(explode(',', env('WATCHPOST_ENVIRONMENTS', 'production')))),

    // Seconds. Reports are sent after the response, so this never delays a visitor.
    'timeout' => (float) env('WATCHPOST_TIMEOUT', 2),

    // Shown next to each error. Defaults to the current git commit when readable.
    'release' => env('WATCHPOST_RELEASE'),

    /*
    |--------------------------------------------------------------------------
    | Privacy
    |--------------------------------------------------------------------------
    |
    | Query string keys containing any of these words are replaced before an
    | error leaves the server. Request bodies, cookies and headers are never sent.
    |
    */

    'redact' => ['password', 'token', 'secret', 'key', 'signature', 'code', 'card', 'cvv', 'pin'],

    /*
    |--------------------------------------------------------------------------
    | Heartbeat & Health
    |--------------------------------------------------------------------------
    |
    | Every minute the scheduler sends a heartbeat with these health checks.
    | A missing heartbeat tells Watchpost that cron or the scheduler stopped.
    |
    */

    'heartbeat' => env('WATCHPOST_HEARTBEAT', true),

    'health' => [
        'database' => true,
        'cache' => true,
        'queue' => [
            'enabled' => true,
            'connection' => null,
            'queue' => null,
            // More pending jobs than this counts as a problem.
            'limit' => (int) env('WATCHPOST_QUEUE_LIMIT', 100),
        ],
        'storage' => [
            'enabled' => true,
            // Disk usage above this percentage counts as a problem.
            'limit' => (int) env('WATCHPOST_STORAGE_LIMIT', 90),
        ],
    ],

    // Frames outside this path, or inside vendor/, are framework frames.
    'base_path' => null,

];
