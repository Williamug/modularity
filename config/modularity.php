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
    | This package sits on top of your existing Laravel app — resolving WHICH
    | tenant a request belongs to is your application's responsibility, not the
    | package's. The recommended approach is to set the tenant yourself once you
    | know it (e.g. in your own middleware or auth flow):
    |
    |     Tenant::set($user->tenant_id);
    |
    | resolvers: an optional, ordered list of built-in convenience strategies
    |   tried on each HTTP request until one returns a non-null tenant ID. The
    |   default is 'session' only (host-controlled and safe). The other built-ins
    |   ('subdomain', 'domain', 'header') are OPT-IN — enable them only if you
    |   understand that a value alone is not authorization. You may also list a
    |   custom FQCN implementing TenantResolverInterface.
    | column: the tenant_id column name used across all module tables.
    | model: optional FQCN of the host application's Tenant Eloquent model,
    |   used only by the opt-in subdomain/domain/header resolvers.
    */
    'tenancy' => [
        'resolvers' => ['session'],
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
    | The package never requires any specific permission system — it only needs
    | a driver that knows how to register a module's permissions and answer
    | userCan() checks. Built-in options:
    |
    |   gate    - Laravel's built-in Gate (default, no extra package needed)
    |   spatie  - optional integration with spatie/laravel-permission
    |   null    - no-op; all permission checks return true (testing only)
    |
    | To use your own system, set this to the FQCN of a class implementing
    | Modularity\Core\Permissions\Contracts\PermissionDriverInterface. It will
    | be resolved from the container, so it may declare its own dependencies.
    */
    'permissions' => [
        'driver' => env('MODULARITY_PERMISSION_DRIVER', 'gate'),
    ],

];
