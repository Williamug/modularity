<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Modules Path
    |--------------------------------------------------------------------------
    | The directory where local modules live. Each subdirectory is scanned
    | for a module.json manifest. Composer-installed modules are discovered
    | automatically via their extra.modularity.module flag.
    */
    'modules_path' => base_path('Modules'),

    /*
    |--------------------------------------------------------------------------
    | Modules Namespace
    |--------------------------------------------------------------------------
    | PSR-4 namespace prefix for locally scaffolded modules.
    */
    'modules_namespace' => 'Modules',

    /*
    |--------------------------------------------------------------------------
    | Core Version
    |--------------------------------------------------------------------------
    | Used for compatibility checks declared in module manifests.
    */
    'version' => '1.0.0',

    /*
    |--------------------------------------------------------------------------
    | Registry Cache
    |--------------------------------------------------------------------------
    | Modularity caches the installed module list and per-tenant active slugs
    | to avoid DB hits on every request. Set enabled to false during development
    | or when using modularity:install / modularity:activate commands.
    */
    'cache' => [
        'enabled' => env('MODULARITY_CACHE', true),
        'ttl'     => 3600,
        'store'   => null, // null = use default cache driver
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenancy
    |--------------------------------------------------------------------------
    | resolvers: ordered list of strategies tried on each HTTP request until
    |   one returns a non-null tenant ID.
    | column: the tenant_id column name used across all module tables.
    | model: optional FQCN of the host application's Tenant Eloquent model.
    |   When set, resolvers can look up by slug/domain.
    */
    'tenancy' => [
        'resolvers' => ['subdomain', 'domain', 'header', 'session'],
        'column'    => 'tenant_id',
        'model'     => env('MODULARITY_TENANT_MODEL', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Migrations Table Prefix
    |--------------------------------------------------------------------------
    | All Modularity infrastructure tables use this prefix. Module business
    | tables are named entirely by the module author.
    */
    'migrations' => [
        'table_prefix' => 'modularity_',
    ],

    /*
    |--------------------------------------------------------------------------
    | Marketplace (Phase 2)
    |--------------------------------------------------------------------------
    | Phase 1 ships null object implementations that bypass all remote calls.
    | Set api_url and api_key in Phase 2 and swap the client binding.
    */
    'marketplace' => [
        'client'  => \Modularity\Marketplace\NullMarketplaceClient::class,
        'api_url' => env('MODULARITY_MARKETPLACE_URL', null),
        'api_key' => env('MODULARITY_MARKETPLACE_KEY', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Permissions Driver
    |--------------------------------------------------------------------------
    | spatie  - requires spatie/laravel-permission (recommended)
    | gate    - falls back to Laravel's built-in Gate (no extra package needed)
    | null    - no-op; all permission checks return true (useful for testing)
    */
    'permissions' => [
        'driver' => env('MODULARITY_PERMISSION_DRIVER', 'gate'),
    ],

];
