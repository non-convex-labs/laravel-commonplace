<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | User model
    |--------------------------------------------------------------------------
    | The Eloquent model that "owns" notes. Configurable so consumer apps
    | aren't forced to use App\Models\User. The class string is resolved
    | lazily — it does not need to exist when this config file loads.
    */

    'user_model' => env('COMMONPLACE_USER_MODEL', 'App\\Models\\User'),

    /*
    |--------------------------------------------------------------------------
    | Routing
    |--------------------------------------------------------------------------
    */

    'routes' => [
        'enabled'    => (bool) env('COMMONPLACE_ROUTES_ENABLED', true),
        'prefix'     => env('COMMONPLACE_ROUTES_PREFIX', 'commonplace'),
        'middleware' => ['web', 'auth'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Embedding provider
    |--------------------------------------------------------------------------
    | Drivers: 'voyage' | 'null'. The Null driver returns zero vectors and is
    | intended for tests, or for installations that want to disable semantic
    | search entirely.
    */

    'embedding' => [
        'driver' => env('COMMONPLACE_EMBEDDING_DRIVER', 'voyage'),

        'voyage' => [
            'api_key'    => env('VOYAGE_API_KEY'),
            'model'      => env('VOYAGE_EMBEDDING_MODEL', 'voyage-3.5'),
            'dimensions' => (int) env('VOYAGE_EMBEDDING_DIMENSIONS', 1024),
        ],

        'null' => [
            'dimensions' => (int) env('COMMONPLACE_NULL_DIMENSIONS', 1024),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reindexing
    |--------------------------------------------------------------------------
    */

    'reindex' => [
        'cooldown_minutes'    => (int) env('COMMONPLACE_REINDEX_COOLDOWN', 60),
        'batch_size'          => (int) env('COMMONPLACE_REINDEX_BATCH_SIZE', 10),
        'batch_delay_seconds' => (int) env('COMMONPLACE_REINDEX_BATCH_DELAY', 25),
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP server
    |--------------------------------------------------------------------------
    | When enabled, the package registers its MCP server routes (HTTP
    | streamable transport) under the configured prefix.
    */

    'mcp' => [
        'enabled' => (bool) env('COMMONPLACE_MCP_ENABLED', false),
        'prefix'  => env('COMMONPLACE_MCP_PREFIX', 'mcp/commonplace'),
    ],

    /*
    |--------------------------------------------------------------------------
    | GitHub backup
    |--------------------------------------------------------------------------
    */

    'backup' => [
        'github' => [
            'repo'  => env('COMMONPLACE_GITHUB_BACKUP_REPO'),
            'token' => env('COMMONPLACE_GITHUB_BACKUP_TOKEN'),
        ],
    ],

];
