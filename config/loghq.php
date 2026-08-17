<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | Project id + public ingest key from your loghq dashboard, or a single
    | DSN of the form https://<key>@<host>/<project>. The ingest key is a
    | public, revocable identifier - it is safe in app config and .env.
    |
    */

    'dsn' => env('LOGHQ_DSN'),
    'project' => env('LOGHQ_PROJECT'),
    'key' => env('LOGHQ_KEY'),
    // Leave this unset. No default here on purpose: a default would override
    // the host embedded in a DSN, since explicit options beat DSN parts. Unset,
    // the SDK's own default applies, and the SDK is what should own where
    // entries go - an app that pins a host has to be found and unpinned when
    // that default moves. Set it only when you genuinely self-host.
    'host' => env('LOGHQ_HOST'),

    /*
    |--------------------------------------------------------------------------
    | Entry metadata
    |--------------------------------------------------------------------------
    */

    'release' => env('LOGHQ_RELEASE'),
    'environment' => env('LOGHQ_ENVIRONMENT'), // defaults to app env

    /*
    |--------------------------------------------------------------------------
    | Behavior
    |--------------------------------------------------------------------------
    */

    'enabled' => env('LOGHQ_ENABLED', true),

    // Records below this severity are dropped before shipping (debug, info,
    // notice, warning, error, critical, alert, emergency). The `loghq` log
    // channel's own `level` gates first; this is a second, SDK-side floor.
    'level' => env('LOGHQ_LEVEL', 'debug'),

    // Keep a fraction of records (0..1) - thin very high-volume streams.
    'sample_rate' => env('LOGHQ_SAMPLE_RATE', 1.0),

    // Buffer this many records before shipping a batch; anything at/above
    // `flush_level` ships immediately regardless.
    'batch_size' => env('LOGHQ_BATCH_SIZE', 25),
    'flush_level' => env('LOGHQ_FLUSH_LEVEL', 'error'),

    /*
    |--------------------------------------------------------------------------
    | Tap the default log stack
    |--------------------------------------------------------------------------
    |
    | OFF by default. When true, loghq appends itself to your default logging
    | stack, so every existing Log::info()/Log::error() call is ALSO shipped to
    | loghq with no code changes. Left off, you opt in explicitly - either add
    | 'loghq' to a stack in config/logging.php, or set LOG_CHANNEL=loghq. This
    | is deliberately opt-in so installing the package never silently starts
    | streaming your logs off-box.
    |
    */

    'tap_stack' => env('LOGHQ_TAP_STACK', false),

    /*
    |--------------------------------------------------------------------------
    | Context
    |--------------------------------------------------------------------------
    |
    | Attach ambient context to each entry (evaluated fresh per record, so a
    | long-lived worker never leaks one request's identity into the next).
    |
    */

    'context' => [
        // Attach the current request (method, url, route, ip, user agent).
        'request' => true,
        // Attach the authenticated user (id + email).
        'user' => true,
    ],

];
