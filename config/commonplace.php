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
        'enabled' => (bool) env('COMMONPLACE_ROUTES_ENABLED', true),
        'prefix' => env('COMMONPLACE_ROUTES_PREFIX', 'commonplace'),
        'middleware' => ['web', 'auth'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Embedding provider
    |--------------------------------------------------------------------------
    | Drivers: 'voyage' | 'openai' | 'cohere' | 'bedrock' | 'null'.
    | The Null driver returns zero vectors and is intended for tests, or for
    | installations that want to disable semantic search entirely.
    |
    | The `dimensions` value drives the vector storage column size. Switching
    | provider or model without re-embedding existing rows will produce garbage
    | results — run `php artisan commonplace:reindex --force` after any such
    | change (without --force, existing rows are skipped).
    */

    'embedding' => [
        'driver' => env('COMMONPLACE_EMBEDDING_DRIVER', 'voyage'),

        'voyage' => [
            'api_key' => env('VOYAGE_API_KEY'),
            'model' => env('VOYAGE_EMBEDDING_MODEL', 'voyage-3.5'),
            'dimensions' => (int) env('VOYAGE_EMBEDDING_DIMENSIONS', 1024),
        ],

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
            // Null when unset or blank: the driver substitutes the model's
            // native size and refuses to forward this to models that don't
            // accept it. `is_numeric` covers `=` (empty string) edge cases.
            'dimensions' => is_numeric(env('OPENAI_EMBEDDING_DIMENSIONS'))
                ? (int) env('OPENAI_EMBEDDING_DIMENSIONS')
                : null,
        ],

        'cohere' => [
            'api_key' => env('COHERE_API_KEY'),
            'model' => env('COHERE_EMBEDDING_MODEL', 'embed-english-v3.0'),
            'dimensions' => (int) env('COHERE_EMBEDDING_DIMENSIONS', 1024),
            // Cohere v3 distinguishes indexing from querying via input_type.
            // Using `search_document` for both measurably degrades retrieval
            // quality; keep these defaults unless you know what you're doing.
            'index_input_type' => env('COHERE_EMBEDDING_INDEX_INPUT_TYPE', 'search_document'),
            'query_input_type' => env('COHERE_EMBEDDING_QUERY_INPUT_TYPE', 'search_query'),
        ],

        'bedrock' => [
            'region' => env('AWS_BEDROCK_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
            'model' => env('BEDROCK_EMBEDDING_MODEL', 'amazon.titan-embed-text-v2:0'),
            'dimensions' => (int) env('BEDROCK_EMBEDDING_DIMENSIONS', 1024),
            'normalize' => (bool) env('BEDROCK_EMBEDDING_NORMALIZE', true),
        ],

        'null' => [
            'dimensions' => (int) env('COMMONPLACE_NULL_DIMENSIONS', 1024),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Vector search driver
    |--------------------------------------------------------------------------
    | Selects how embeddings are stored and how similarity search is executed.
    |
    | - 'in_php_cosine' (default): JSON storage, cosine computed in PHP.
    |   Works on any database. Best for personal/single-user installs;
    |   bounded by `max_candidates` / `hard_max_candidates`.
    |
    | - 'pgvector': PostgreSQL + pgvector extension. Indexed similarity
    |   search via Laravel 13's native vector query builders. Requires
    |   publishing the pgvector migration:
    |     php artisan vendor:publish --tag=commonplace-pgvector-migration
    |
    | - 'null': Semantic search disabled. Storage and search are no-ops.
    |
    | Run `php artisan commonplace:doctor` for a guided check.
    */

    'vector' => [
        'driver' => env('COMMONPLACE_VECTOR_DRIVER', 'in_php_cosine'),

        'in_php_cosine' => [
            'max_candidates' => (int) env('COMMONPLACE_INPHP_MAX_CANDIDATES', 2000),
            'hard_max_candidates' => (int) env('COMMONPLACE_INPHP_HARD_MAX_CANDIDATES', 20000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reindexing
    |--------------------------------------------------------------------------
    */

    'reindex' => [
        'cooldown_minutes' => (int) env('COMMONPLACE_REINDEX_COOLDOWN', 60),
        'batch_size' => (int) env('COMMONPLACE_REINDEX_BATCH_SIZE', 10),
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
        'prefix' => env('COMMONPLACE_MCP_PREFIX', 'mcp/commonplace'),
    ],

    /*
    |--------------------------------------------------------------------------
    | GitHub backup
    |--------------------------------------------------------------------------
    */

    'backup' => [
        'github' => [
            'repo' => env('COMMONPLACE_GITHUB_BACKUP_REPO'),
            'token' => env('COMMONPLACE_GITHUB_BACKUP_TOKEN'),
        ],
    ],

];
