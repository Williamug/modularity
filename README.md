# Modularity Core

Transform any Laravel application into a modular, marketplace-driven SaaS platform with first-class multi-tenant support.

- **Module discovery** — local `Modules/` directory or Composer packages
- **Full lifecycle** — install, activate per-tenant, upgrade, deactivate, remove
- **Multi-tenancy** — subdomain, domain, header, or session resolution; automatic Eloquent scoping
- **Navigation** — tenant-aware, permission-filtered menu registry
- **Permissions** — pluggable drivers (Gate, Spatie, Null)
- **Dependency graph** — topological sort with circular-dependency detection
- **Marketplace-ready** — Phase 2 billing/subscription contracts already defined

---

## Table of Contents

1. [Requirements](#requirements)
2. [Installation](#installation)
3. [Configuration](#configuration)
4. [Core Concepts](#core-concepts)
   - [Module Manifest](#module-manifest)
   - [Module Lifecycle](#module-lifecycle)
   - [Multi-Tenancy](#multi-tenancy)
   - [Navigation](#navigation)
   - [Permissions](#permissions)
5. [Authoring a Module](#authoring-a-module)
6. [Artisan Commands](#artisan-commands)
7. [Facade API](#facade-api)
8. [Dependency Injection API](#dependency-injection-api)
9. [Events](#events)
10. [Exceptions](#exceptions)
11. [Database Schema](#database-schema)
12. [Testing](#testing)
13. [Publishing Assets](#publishing-assets)
14. [Marketplace (Phase 2)](#marketplace-phase-2)
15. [Troubleshooting](#troubleshooting)

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | `^8.2` |
| Laravel / Illuminate | `>=11.0` |
| [spatie/laravel-permission](https://github.com/spatie/laravel-permission) | Optional — required only when `permissions.driver = spatie` |
| [livewire/livewire](https://livewire.laravel.com) | Optional — required only when scaffolding Livewire components |

---

## Installation

```bash
composer require modularity/core
```

Laravel's package auto-discovery registers the service provider and both facades automatically. If you have auto-discovery disabled, add manually to `config/app.php`:

```php
'providers' => [
    Modularity\ModularityServiceProvider::class,
],
'aliases' => [
    'Module' => Modularity\Support\Facades\Module::class,
    'Tenant' => Modularity\Support\Facades\Tenant::class,
],
```

Publish and run migrations:

```bash
php artisan vendor:publish --tag=modularity-migrations
php artisan migrate
```

Publish the config file (optional):

```bash
php artisan vendor:publish --tag=modularity-config
```

Register the tenant middleware in your HTTP kernel (or `bootstrap/app.php` for Laravel 11):

```php
// Laravel 11 — bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'resolve.tenant' => \Modularity\Http\Middleware\ResolveTenantMiddleware::class,
    ]);
    $middleware->appendToGroup('web', \Modularity\Http\Middleware\ResolveTenantMiddleware::class);
})
```

---

## Configuration

`config/modularity.php` (published via `--tag=modularity-config`):

```php
return [
    // Where local modules live; each subdirectory must contain module.json
    'modules_path'      => base_path('Modules'),

    // PSR-4 namespace prefix for scaffolded modules
    'modules_namespace' => 'Modules',

    // Used for compatibility checks in module manifests
    'version' => '1.0.0',

    'cache' => [
        'enabled' => env('MODULARITY_CACHE', true),
        'ttl'     => 3600,          // seconds
        'store'   => null,          // null = default cache driver
    ],

    'tenancy' => [
        // Resolver chain: tried in order until one returns a tenant ID
        'resolvers' => ['subdomain', 'domain', 'header', 'session'],
        // Column name used in all Modularity tables
        'column'    => 'tenant_id',
        // FQCN of the host app's Tenant model (enables slug/domain lookup)
        'model'     => env('MODULARITY_TENANT_MODEL', null),
    ],

    'migrations' => [
        'table_prefix' => 'modularity_',
    ],

    'marketplace' => [
        'client'  => \Modularity\Marketplace\NullMarketplaceClient::class,
        'api_url' => env('MODULARITY_MARKETPLACE_URL', null),
        'api_key' => env('MODULARITY_MARKETPLACE_KEY', null),
    ],

    'permissions' => [
        // 'spatie' | 'gate' | 'null'
        'driver' => env('MODULARITY_PERMISSION_DRIVER', 'gate'),
    ],
];
```

**Environment variables:**

```dotenv
MODULARITY_CACHE=true
MODULARITY_PERMISSION_DRIVER=gate        # gate | spatie | null
MODULARITY_TENANT_MODEL=App\Models\Tenant
MODULARITY_MARKETPLACE_URL=
MODULARITY_MARKETPLACE_KEY=
```

---

## Core Concepts

### Module Manifest

Every module must contain a `module.json` file at its root:

```json
{
    "name": "Library",
    "slug": "library",
    "version": "1.0.0",
    "description": "Digital library management module",
    "providers": [
        "Modules\\Library\\Providers\\LibraryServiceProvider"
    ],
    "permissions": [
        "library.view",
        "library.create",
        "library.update",
        "library.delete"
    ],
    "dependencies": ["core-billing"],
    "compatibility": "^1.0"
}
```

| Field | Required | Description |
|---|---|---|
| `name` | Yes | Human-readable display name |
| `slug` | Yes | Unique kebab-case identifier (e.g. `library`, `my-module`) |
| `version` | Yes | SemVer string |
| `providers` | Yes | Array of fully-qualified service provider class names |
| `description` | No | Short description |
| `permissions` | No | Permission strings registered at install time |
| `dependencies` | No | Array of module slugs that must be installed first |
| `compatibility` | No | SemVer constraint against Modularity core version |

> **Slug format**: must match `/^[a-z0-9]+(?:-[a-z0-9]+)*$/` (lowercase, numbers, hyphens).

---

### Module Lifecycle

```
discover → install → activate (per-tenant) → [upgrade] → deactivate → remove
```

| State | Storage | Scope |
|---|---|---|
| **Discovered** | Memory / filesystem | Global |
| **Installed** | `modularity_installed_modules` table | Global |
| **Active** | `modularity_tenant_modules` table | Per-tenant |

**Transitions:**

```
php artisan module:install  library       # Discovered → Installed (runs migrations)
php artisan module:activate library --tenant=1   # Installed → Active for tenant 1
php artisan module:upgrade  library       # Runs pending migrations, bumps version
php artisan module:deactivate library --tenant=1 # Active → Inactive for tenant 1
php artisan module:remove   library       # Installed → Removed (never rolls back migrations)
```

**What install does:**
1. Validates all declared `dependencies` are already installed
2. Runs module migrations from `database/migrations/`
3. Registers declared permissions with the permission driver
4. Creates an `InstalledModule` record
5. Dispatches `ModuleInstalled` event

**What activate does:**
1. Creates or updates a `TenantModule` record with `active = true`
2. Invalidates the registry cache for that tenant
3. Dispatches `ModuleActivated` event

**Module removal** does not roll back migrations. Data is preserved even after removal.

---

### Multi-Tenancy

Modularity resolves the current tenant on every HTTP request via a chain of strategies:

| Resolver | Strategy |
|---|---|
| `subdomain` | Extracts subdomain, looks up `Tenant` model by slug/domain |
| `domain` | Full hostname lookup in `Tenant` model |
| `header` | Reads `X-Tenant-ID` HTTP header |
| `session` | Reads `modularity_tenant_id` from session |

The chain runs in the order declared in `tenancy.resolvers`. The first resolver that returns a non-null ID wins.

**Setting tenant context manually (e.g. in tests or CLI):**

```php
use Modularity\Support\Facades\Tenant;

Tenant::set(1);

// or via DI:
app(\Modularity\Core\Tenancy\TenantContext::class)->set(1);
```

**Automatic Eloquent scoping** — apply `BelongsToTenant` to any module model to automatically scope queries and set `tenant_id` on creation:

```php
use Modularity\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Book extends Model
{
    use BelongsToTenant;
    // All queries automatically filtered by current tenant_id
}
```

**Accessing tenant context:**

```php
use Modularity\Support\Facades\Tenant;

$id    = Tenant::id();        // ?int — null if not resolved
$isSet = Tenant::isSet();     // bool
$id    = Tenant::assertSet(); // int — throws TenantNotResolvedException if null
Tenant::forget();             // Clear context
```

---

### Navigation

Module service providers register menu items during boot. The registry filters them by active module and user permission:

**Registering a menu item:**

```php
// In your ModuleServiceProvider::registerModuleNavigation()
use Modularity\Support\Facades\Module;

Module::menu()->add([
    'module'     => 'library',
    'label'      => 'Library',
    'icon'       => 'book-open',
    'route'      => 'library.index',
    'permission' => 'library.view',   // optional
    'order'      => 50,               // lower = earlier (default: 100)
    'group'      => 'content',        // default: 'general'
]);
```

**Rendering the menu in a Blade view:**

```php
use Modularity\Support\Facades\Module;

// Flat, sorted by 'order':
$items = Module::menu()->forTenant(Tenant::id(), auth()->user());

// Grouped by 'group':
$groups = Module::menu()->forTenantGrouped(Tenant::id(), auth()->user());
```

**MenuItem fields:**

| Field | Type | Default | Description |
|---|---|---|---|
| `module` | `string` | — | Owning module slug (used for active check) |
| `label` | `string` | — | Display text |
| `route` | `string` | — | Named Laravel route |
| `icon` | `string` | `null` | Icon identifier (e.g. Heroicons name) |
| `permission` | `string` | `null` | Gate ability — item hidden if user lacks it |
| `order` | `int` | `100` | Sort order (ascending) |
| `group` | `string` | `general` | Group label for grouped rendering |
| `children` | `array` | `[]` | Nested menu items |

---

### Permissions

Three drivers are available. Set via `MODULARITY_PERMISSION_DRIVER`.

| Driver | Config value | Requires |
|---|---|---|
| Laravel Gate | `gate` | Nothing extra |
| Spatie Permission | `spatie` | `spatie/laravel-permission` |
| Null (no-op) | `null` | Nothing — all checks return `true` |

Permissions declared in `module.json` are registered automatically at install time. Using the Gate driver, they become abilities on Laravel's `Gate` instance.

```php
// Check programmatically:
Module::permissions()->userCan($user, 'library.view');

// Or use standard Laravel Gate/Policy:
$this->authorize('library.view');
Gate::allows('library.create');
```

---

## Authoring a Module

### Scaffold a new module

```bash
php artisan module:make-module Library
# With Livewire:
php artisan module:make-module Library --livewire
```

This creates `Modules/Library/` with the following structure:

```
Modules/Library/
├── module.json
├── src/
│   ├── Providers/
│   │   └── LibraryServiceProvider.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── LibraryController.php
│   │   └── Livewire/              (with --livewire)
│   │       └── LibraryComponent.php
│   ├── Models/
│   │   └── Library.php
│   ├── Services/
│   ├── Events/
│   │   └── LibraryCreated.php
│   ├── Listeners/
│   │   └── OnLibraryCreated.php
│   └── Policies/
│       └── LibraryPolicy.php
├── database/
│   └── migrations/
├── routes/
│   ├── web.php
│   └── api.php
├── resources/
│   └── views/
│       └── index.blade.php
└── tests/
```

### Service Provider

```php
namespace Modules\Library\Providers;

use Modularity\Support\Abstracts\ModuleServiceProvider;
use Modularity\Support\Facades\Module;

class LibraryServiceProvider extends ModuleServiceProvider
{
    // REQUIRED: must match the slug in module.json
    protected string $slug = 'library';

    protected string $version = '1.0.0';

    // Event → Listener wiring (auto-registered when module is active)
    protected array $listen = [
        \Modules\Library\Events\LibraryCreated::class => [
            \Modules\Library\Listeners\OnLibraryCreated::class,
        ],
    ];

    // Livewire component aliases (auto-registered when module is active)
    protected array $livewireComponents = [
        'library-books' => \Modules\Library\Http\Livewire\BooksComponent::class,
    ];

    protected function registerModuleNavigation(): void
    {
        Module::menu()->add([
            'module'     => 'library',
            'label'      => 'Library',
            'icon'       => 'book-open',
            'route'      => 'library.index',
            'permission' => 'library.view',
            'order'      => 50,
            'group'      => 'content',
        ]);
    }
}
```

> **Important:** `ModuleServiceProvider::boot()` is a no-op when the module is not active for the current tenant. Routes, views, listeners, and navigation items are only registered when `Module::active($slug)` returns `true`.

### Module Manifest (`module.json`)

```json
{
    "name": "Library",
    "slug": "library",
    "version": "1.0.0",
    "description": "Digital library management",
    "providers": [
        "Modules\\Library\\Providers\\LibraryServiceProvider"
    ],
    "permissions": [
        "library.view",
        "library.create",
        "library.update",
        "library.delete"
    ],
    "dependencies": [],
    "compatibility": "^1.0"
}
```

### Tenant-Scoped Models

Extend `ModuleModel` (which already uses `BelongsToTenant`):

```php
namespace Modules\Library\Models;

use Modularity\Support\Abstracts\ModuleModel;

class Book extends ModuleModel
{
    protected $fillable = ['title', 'author', 'isbn'];
    // tenant_id is automatically set on create and filtered on all queries
}
```

Or apply the trait to any existing model:

```php
use Modularity\Support\Traits\BelongsToTenant;

class Book extends Model
{
    use BelongsToTenant;
}
```

### Routes

Routes are auto-loaded when the module is active. No manual registration needed:

```php
// routes/web.php — automatically wrapped in 'web' middleware group
Route::middleware(['auth'])->group(function () {
    Route::get('/library', [LibraryController::class, 'index'])->name('library.index');
    Route::get('/library/{book}', [LibraryController::class, 'show'])->name('library.show');
});
```

```php
// routes/api.php — automatically wrapped in 'api' middleware and prefixed with /api
Route::middleware(['auth:sanctum'])->group(function () {
    Route::apiResource('books', BookApiController::class);
});
```

### Views

View files in `resources/views/` are auto-loaded under the module's slug namespace:

```php
// In a controller:
return view('library::index', compact('books'));
return view('library::books.show', compact('book'));
```

### Migrations

Place migrations in `database/migrations/`. They run at `module:install` time and are tracked in `modularity_migration_log`:

```php
// database/migrations/2024_01_01_000001_create_books_table.php
return new class extends Migration {
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('title');
            $table->string('author');
            $table->timestamps();
        });
    }
};
```

### Module-specific Settings

Per-tenant settings are stored as JSON in `modularity_tenant_modules.settings`. Access them via:

```php
// Get a setting with a default:
$pageSize = Module::config('library', 'pagination.per_page', 15);

// Update settings (e.g. in an admin controller):
TenantModule::forTenant($tenantId)->forModule('library')->update([
    'settings' => ['pagination' => ['per_page' => 25]],
]);
```

### Publishing a Module via Composer

To distribute a module as a Composer package, add this to the module's `composer.json`:

```json
{
    "extra": {
        "modularity": {
            "module": true
        }
    }
}
```

Modularity will automatically discover it alongside local modules.

---

## Artisan Commands

### `module:make-module`

Scaffold a new module directory with all boilerplate.

```bash
php artisan module:make-module <Name> [--path=] [--livewire]
```

| Option | Description |
|---|---|
| `Name` | PascalCase module name (e.g. `Library`, `BillingReports`) |
| `--path` | Override the output directory (default: `Modules/<Name>`) |
| `--livewire` | Also scaffold a Livewire component |

---

### `module:install`

Install a module globally (runs migrations, registers permissions).

```bash
php artisan module:install <slug> [--path=]
```

| Option | Description |
|---|---|
| `slug` | Module slug as declared in `module.json` |
| `--path` | Explicit path to `module.json` (for modules not yet discovered) |

Idempotent: safe to run multiple times.

---

### `module:activate`

Activate a module for a specific tenant.

```bash
php artisan module:activate <slug> --tenant=<id>
```

The module must be installed before it can be activated.

---

### `module:deactivate`

Deactivate a module for a specific tenant or all tenants.

```bash
php artisan module:deactivate <slug> --tenant=<id>
php artisan module:deactivate <slug> --all-tenants
```

---

### `module:upgrade`

Run pending migrations for an installed module and bump its version.

```bash
php artisan module:upgrade <slug>
```

---

### `module:remove`

Remove a module from the platform. Does **not** roll back migrations.

```bash
php artisan module:remove <slug> [--force]
```

| Option | Description |
|---|---|
| `--force` | Auto-deactivate all tenants before removal. Without this flag, the command aborts if any tenant has the module active. |

---

### `module:list`

List all discovered, installed, and active modules.

```bash
php artisan module:list [--tenant=<id>]
```

Shows slug, name, version, installed status, and (with `--tenant`) whether the module is active for that tenant.

---

### `module:status`

Show detailed status for a specific module.

```bash
php artisan module:status <slug>
```

---

### `module:make-livewire`

Scaffold a Livewire component within a module context.

```bash
php artisan module:make-livewire <slug> <ComponentName>
```

---

## Facade API

### `Module` facade

```php
use Modularity\Support\Facades\Module;

// Check if a module is active for the current tenant
Module::active(string $slug): bool

// Check if a module is active for a specific tenant
Module::activeFor(string $slug, int $tenantId): bool

// Check if a module is globally installed
Module::installed(string $slug): bool

// Check if a module has been discovered (local or Composer)
Module::discovered(string $slug): bool

// Access the NavigationRegistry
Module::menu(): NavigationRegistry

// Access the PermissionRegistry
Module::permissions(): PermissionRegistry

// Get a per-tenant module setting
Module::config(string $slug, string $key, mixed $default = null): mixed

// Access the underlying ModuleRegistry
Module::registry(): ModuleRegistry
```

### `Tenant` facade

```php
use Modularity\Support\Facades\Tenant;

Tenant::set(int $tenantId): void    // Set current tenant
Tenant::id(): ?int                   // Get current tenant ID (null if not set)
Tenant::isSet(): bool                // Check if a tenant is active
Tenant::forget(): void               // Clear the current tenant
Tenant::assertSet(): int             // Get ID or throw TenantNotResolvedException
```

---

## Dependency Injection API

All core services are available via Laravel's service container:

```php
use Modularity\Core\Module\ModuleManager;
use Modularity\Core\Module\ModuleRegistry;
use Modularity\Core\Tenancy\TenantContext;
use Modularity\Core\Navigation\NavigationRegistry;
use Modularity\Core\Permissions\PermissionRegistry;
use Modularity\Core\Lifecycle\ModuleInstaller;
use Modularity\Core\Lifecycle\ModuleActivator;
use Modularity\Core\Lifecycle\ModuleDeactivator;
use Modularity\Core\Lifecycle\ModuleUpgrader;
use Modularity\Core\Lifecycle\ModuleRemover;

class SaasAdminController extends Controller
{
    public function __construct(
        private readonly ModuleInstaller $installer,
        private readonly ModuleActivator $activator,
        private readonly ModuleDeactivator $deactivator,
    ) {}

    public function install(string $slug): void
    {
        $this->installer->install($slug);
    }

    public function activateForTenant(string $slug, int $tenantId): void
    {
        $this->activator->activate($slug, $tenantId);
    }
}
```

### ModuleRegistry

```php
$registry = app(ModuleRegistry::class);

$registry->getManifest(string $slug): ?ManifestDTO
$registry->getInstalledRecord(string $slug): ?InstalledModule
$registry->isInstalled(string $slug): bool
$registry->isDiscovered(string $slug): bool
$registry->activeFor(string $slug, int $tenantId): bool
$registry->invalidateInstalled(): void
$registry->invalidateActive(int $tenantId): void
```

### NavigationRegistry

```php
$nav = Module::menu(); // or app(NavigationRegistry::class)

$nav->add(array|MenuItem $item): void
$nav->forTenant(int $tenantId, ?object $user = null): Collection
$nav->forTenantGrouped(int $tenantId, ?object $user = null): Collection
$nav->all(): array
$nav->flush(): void
```

---

## Events

Listen to module lifecycle events anywhere in your application:

```php
use Modularity\Events\ModuleInstalled;
use Modularity\Events\ModuleActivated;
use Modularity\Events\ModuleDeactivated;
use Modularity\Events\ModuleUpgraded;
use Modularity\Events\ModuleRemoved;

// In EventServiceProvider:
protected $listen = [
    ModuleInstalled::class  => [SendWelcomeNotification::class],
    ModuleActivated::class  => [ProvisionTenantResources::class],
    ModuleDeactivated::class => [RevokeAccess::class],
    ModuleUpgraded::class   => [NotifyAdmins::class],
    ModuleRemoved::class    => [CleanupArtifacts::class],
];
```

**Event payloads:**

| Event | Properties |
|---|---|
| `ModuleInstalled` | `ManifestDTO $manifest` |
| `ModuleActivated` | `string $slug`, `int $tenantId` |
| `ModuleDeactivated` | `string $slug`, `int $tenantId` |
| `ModuleUpgraded` | `string $slug`, `string $oldVersion`, `string $newVersion` |
| `ModuleRemoved` | `string $slug` |

`ModuleActivated` and `ModuleDeactivated` implement `TenantAwareEvent`.

---

## Exceptions

All exceptions are in `Modularity\Core\Module\Exceptions\` or `Modularity\Core\Tenancy\Exceptions\`:

| Exception | When thrown |
|---|---|
| `ModuleNotFoundException` | Slug not found in registry |
| `ModuleNotInstalledException` | Activation attempted on uninstalled module |
| `ModuleAlreadyInstalledException` | Install attempted on already-installed module |
| `ModuleStillActiveException` | Remove attempted while tenants still have module active |
| `DependencyNotInstalledException` | Install attempted when a declared dependency is missing |
| `InvalidManifestException` | `module.json` is malformed or missing required fields |
| `CircularDependencyException` | Circular dependency detected in dependency graph |
| `TenantNotResolvedException` | `Tenant::assertSet()` called with no active tenant |

---

## Database Schema

Modularity creates four tables, all prefixed with `modularity_` (configurable):

### `modularity_installed_modules`

| Column | Type | Description |
|---|---|---|
| `id` | `bigint` PK | |
| `slug` | `varchar` UNIQUE | Module slug |
| `name` | `varchar` | Display name |
| `version` | `varchar` | SemVer |
| `checksum` | `varchar` nullable | MD5 of `module.json` |
| `status` | `enum` | `installed` or `errored` |
| `installed_at` | `timestamp` | |
| `created_at` | `timestamp` | |
| `updated_at` | `timestamp` | |

### `modularity_tenant_modules`

| Column | Type | Description |
|---|---|---|
| `id` | `bigint` PK | |
| `tenant_id` | `bigint` | FK to tenant (application-defined) |
| `module_slug` | `varchar` | Module slug |
| `active` | `boolean` | Whether the module is currently active |
| `settings` | `json` nullable | Per-tenant module configuration |
| `activated_at` | `timestamp` nullable | |
| `deactivated_at` | `timestamp` nullable | |

Unique constraint: `(tenant_id, module_slug)`.

### `modularity_tenant_module_subscriptions`

| Column | Type | Description |
|---|---|---|
| `id` | `bigint` PK | |
| `tenant_id` | `bigint` | |
| `module_slug` | `varchar` | |
| `status` | `enum` | `active`, `trial`, `free`, `cancelled` |
| `billing_cycle` | `enum` nullable | `monthly`, `yearly` |
| `starts_at` | `timestamp` nullable | |
| `expires_at` | `timestamp` nullable | |

Phase 2 — currently unpopulated.

### `modularity_migration_log`

| Column | Type | Description |
|---|---|---|
| `id` | `bigint` PK | |
| `module_slug` | `varchar` | Owning module |
| `migration_file` | `varchar` | Migration filename (without path) |
| `batch` | `int` | Batch number |
| `ran_at` | `timestamp` | |

---

## Testing

Extend `Modularity\Tests\TestCase` for pre-configured test cases:

```php
use Modularity\Tests\TestCase;

class LibraryTest extends TestCase
{
    public function test_books_are_scoped_to_tenant(): void
    {
        Tenant::set(1);

        Book::create(['title' => 'Tenant 1 Book', 'author' => 'Author A']);

        Tenant::set(2);

        $this->assertCount(0, Book::all()); // Not visible to tenant 2
    }
}
```

The base `TestCase`:
- Uses SQLite in-memory database
- Disables the module registry cache (`MODULARITY_CACHE=false`)
- Sets the permission driver to `null` (all checks pass)
- Auto-registers `Module` and `Tenant` facades
- Runs Modularity's own migrations automatically

**Running tests:**

```bash
./vendor/bin/pest
./vendor/bin/pest --filter "LibraryTest"
```

---

## Publishing Assets

| Tag | Contents |
|---|---|
| `modularity-config` | `config/modularity.php` |
| `modularity-migrations` | Four infrastructure migration files |
| `modularity-stubs` | Module scaffolding stubs (to customize `module:make-module` output) |

```bash
php artisan vendor:publish --tag=modularity-config
php artisan vendor:publish --tag=modularity-migrations
php artisan vendor:publish --tag=modularity-stubs
```

Stubs are published to `stubs/modularity/` in the application root.

---

## Marketplace (Phase 2)

The marketplace system is designed but uses Null Object implementations in Phase 1. Three contracts are defined:

```php
Modularity\Marketplace\Contracts\MarketplaceClientInterface
Modularity\Marketplace\Contracts\LicenseVerifierInterface
Modularity\Marketplace\Contracts\SubscriptionManagerInterface
```

To wire in a real implementation:

```php
// In a service provider:
$this->app->bind(
    \Modularity\Marketplace\Contracts\MarketplaceClientInterface::class,
    \App\Marketplace\MyMarketplaceClient::class,
);
```

---

## Troubleshooting

**Module routes are not loading**

The module's service provider only boots when the module is active for the current tenant. Verify with:

```bash
php artisan module:list --tenant=<id>
```

**Tenant is not being resolved**

- Ensure `ResolveTenantMiddleware` is registered and runs on the route
- Check that your resolver order in `tenancy.resolvers` is correct
- For subdomain/domain resolvers, ensure `MODULARITY_TENANT_MODEL` is set to your `Tenant` model FQCN

**Cache is stale after install/activate**

Set `MODULARITY_CACHE=false` during development, or manually flush:

```php
app(\Modularity\Core\Module\ModuleRegistry::class)->invalidateInstalled();
```

**`InvalidManifestException` on install**

The `module.json` is missing a required field or the `slug` does not match the kebab-case format `/^[a-z0-9]+(?:-[a-z0-9]+)*$/`.

**`DependencyNotInstalledException`**

Install the dependency module first:

```bash
php artisan module:install <dependency-slug>
php artisan module:install <dependent-slug>
```

**Permission checks always return `false` with Gate driver**

When using the `gate` driver, permissions are registered with Laravel's Gate as simple `true` abilities at install time. Ensure you are not overriding them in `AuthServiceProvider`.

**Migrations don't run for a module**

Migrations must be in `database/migrations/` relative to the module root (where `module.json` lives). The path is derived from `ManifestDTO::$path`.

---

## License

MIT — see [LICENSE](LICENSE).
