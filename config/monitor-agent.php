<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Monitor Agent
    |--------------------------------------------------------------------------
    |
    | The agent reports exceptions, logs and queued jobs to your Monitor panel.
    | It stays off without a token, while running tests, and by default in
    | the local environment.
    |
    */

    'enabled' => (bool) env('MONITOR_ENABLED', env('APP_ENV') !== 'local'),

    'url' => env('MONITOR_URL'),

    'token' => env('MONITOR_TOKEN'),

    // Lowest PSR-3 level that is reported (debug, info, notice, warning, error...).
    'log_level' => env('MONITOR_LOG_LEVEL', 'info'),

    // Long-running processes send a batch once it reaches this many events...
    'batch_size' => 100,

    // ...or once this many seconds have passed since the last batch.
    'flush_interval' => 10,

    // Events beyond this are dropped until the next batch is sent.
    'max_buffer' => 1000,

    // Encoded size kept in memory, below the panel's 5 MB batch limit. Logs, queries
    // and outgoing calls may use two thirds of it, so exceptions still fit after a burst.
    'max_buffer_bytes' => 4_500_000,

    // Seconds to stop sending after the panel fails, rate limits or times out.
    'cooldown' => 30,

    // Seconds to wait for the panel before giving up on a batch.
    'timeout' => 2,

    'connect_timeout' => 1,

    'max_frames' => 50,

    'max_context_bytes' => 10240,

    // Context keys containing any of these words are replaced by "[redacted]".
    'redact_keys' => [
        'password', 'password_confirmation', 'token', 'secret', 'authorization', 'cookie',
        'api_key', 'api-key', 'apikey', 'private_key', 'pwd', 'access_token', 'refresh_token',
        'credit_card', 'card_number', 'cvv',
    ],

];
