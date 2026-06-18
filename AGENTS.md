# Modularity Core — AI Agent Guide

> This file is an authoritative guide for AI coding assistants (Claude, GitHub Copilot, OpenAI Codex, Cursor, Codeium, etc.) working in this repository or in a Laravel application that uses `modularity/core`. Read this before suggesting code, generating scaffolding, or diagnosing bugs.

---

## What This Package Is

`modularity/core` is a **Laravel package** that turns any Laravel 11+ application into a **modular, multi-tenant SaaS platform**. It provides:

- **Module discovery** from a local `Modules/` directory or Composer packages
- **Lifecycle management** — install, activate per-tenant, upgrade, deactivate, remove
- **Multi-tenancy** — HTTP-layer tenant resolution with automatic Eloquent scoping
- **Navigation registry** — tenant-aware, permission-filtered menu system
- **Pluggable permission drivers** — Gate, Spatie, or Null
- **Dependency graph** — modules declare dependencies; topological sort prevents ordering issues
- **Marketplace contracts** — Phase 2 billing/subscription stubs already in place

---

## Repository Layout

```
src/
├── Console/Commands/          # 9 Artisan commands (module:*)
├── Core/
│   ├── Lifecycle/             # Installer, Activator, Deactivator, Upgrader, Remover
│   ├── Module/                # ModuleRegistry, ModuleLoader, ModuleManifest, ManifestDTO
│   │                          # ModuleManager (main orchestrator), DependencyGraph
│   ├── Navigation/            # NavigationRegistry, MenuItem, MenuGroup
│   ├── Permissions/           # PermissionRegistry + Gate/Spatie/Null drivers
│   └── Tenancy/               # TenantContext, TenantResolver, TenantScope + 4 resolvers
├── Events/                    # 5 lifecycle events
├── Http/Middleware/           # ResolveTenantMiddleware
├── Listeners/                 # CacheInvalidationListener
├── Marketplace/               # Phase 2 contracts + Null implementations
├── Models/                    # InstalledModule, TenantModule, TenantModuleSubscription,
│                              # ModuleMigrationLog
├── Support/
│   ├── Abstracts/             # ModuleServiceProvider (base), ModuleModel (base)
│   ├── Facades/               # Module, Tenant
│   └── Traits/                # BelongsToTenant
└── ModularityServiceProvider.php
config/modularity.php
database/migrations/           # 4 infrastructure tables
stubs/module/                  # Scaffolding templates
tests/
```

---

## Namespace and Entry Points

| Thing | FQCN / location |
|---|---|
| Primary facade | `Modularity\Support\Facades\Module` |
| Tenant facade | `Modularity\Support\Facades\Tenant` |
| Core orchestrator | `Modularity\Core\Module\ModuleManager` |
| Base module SP | `Modularity\Support\Abstracts\ModuleServiceProvider` |
| Base module model | `Modularity\Support\Abstracts\ModuleModel` |
| Tenant trait | `Modularity\Support\Traits\BelongsToTenant` |
| Package SP | `Modularity\ModularityServiceProvider` |

---

## Mental Model: The Three Layers

```
┌──────────────────────────────────────────────────────┐
│  Module Authors                                       │
│  ModuleServiceProvider (extend) + module.json        │
│  ModuleModel (extend) + BelongsToTenant (trait)      │
└───────────────────────┬──────────────────────────────┘
                        │ uses
┌───────────────────────▼──────────────────────────────┐
│  Package Consumers (host Laravel app)                 │
│  Module::active()  Module::menu()  Tenant::set()     │
│  module:install / module:activate  (Artisan)         │
└───────────────────────┬──────────────────────────────┘
                        │ backed by
┌───────────────────────▼──────────────────────────────┐
│  Internals                                            │
│  ModuleRegistry  ModuleLoader  ModuleManifest        │
│  TenantContext   TenantResolver  TenantScope         │
│  PermissionRegistry  NavigationRegistry              │
│  Lifecycle managers (Installer, Activator, ...)      │
└──────────────────────────────────────────────────────┘
```

Application code and module authors operate at the top two layers only. **Never access internal classes directly** when the Facade or ModuleManager exposes the same operation.

---

## The Module Lifecycle

```
[filesystem / Composer]
        │  discover (automatic on boot)
        ▼
   Discovered
        │  module:install  (runs DB migrations, registers permissions)
        ▼
   Installed  ──────────────────────────────────────────────────────┐
        │  module:activate --tenant=N                               │
        ▼                                                           │
  Active (per-tenant) ──► module:upgrade  (runs pending migrations) │
        │  module:deactivate --tenant=N                             │
        ▼                                                           │
  Inactive (per-tenant)                                             │
        │  module:remove  (requires all tenants deactivated)        │
        ▼                                                           │
   [removed] ◄──────────────────────────────────────────────────────┘
```

Key invariants:
- A module cannot be activated unless it is installed
- A module cannot be removed while any tenant has it active (unless `--force`)
- Migrations are **never rolled back** — even after removal, data is preserved
- Install is **idempotent** — safe to run multiple times

---

## How Tenant Resolution Works

`ResolveTenantMiddleware` runs on every HTTP request. It tries four strategies in the order declared in `config/modularity.php` → `tenancy.resolvers`:

1. **subdomain** — extracts the first subdomain segment; looks it up in the configured `Tenant` model
2. **domain** — looks up the full hostname in the `Tenant` model
3. **header** — reads `X-Tenant-ID` from the HTTP request headers
4. **session** — reads `modularity_tenant_id` from the session

The **first resolver that returns a non-null ID** sets `TenantContext`. Subsequent resolvers are not called.

After the request, the context is reset to prevent bleed in long-running processes (queues, Octane).

---

## Critical Rules for Code Generation

### 1. Module service providers must declare `$slug`

```php
// CORRECT
class LibraryServiceProvider extends ModuleServiceProvider
{
    protected string $slug = 'library'; // must match slug in module.json
}

// WRONG — will cause runtime errors
class LibraryServiceProvider extends ModuleServiceProvider
{
    // Missing $slug
}
```

### 2. Boot logic is gated by `moduleIsActive()`

`ModuleServiceProvider::boot()` calls `$this->moduleIsActive()` first. If the module is not active for the current tenant, **the entire boot method returns early**. Do not put logic in `boot()` that must run unconditionally — put it in `register()` instead.

```php
// WRONG: this navigation item only gets added when the module is active,
// which is correct behavior — but authors sometimes get confused about WHY
// items don't appear.
protected function registerModuleNavigation(): void
{
    Module::menu()->add([...]); // only runs when active — expected!
}
```

### 3. Never use `Module::active()` inside a module's own routes

Route files are only loaded when the module is active. You do not need an additional active check inside them:

```php
// WRONG — redundant
Route::middleware(['web', function($req, $next) {
    abort_unless(Module::active('library'), 403);
    return $next($req);
}])->group(function () { ... });

// CORRECT — routes are not loaded if the module is not active
Route::middleware(['web', 'auth'])->group(function () { ... });
```

### 4. Always include `tenant_id` in module migration tables

Every module table that holds tenant-specific data needs a `tenant_id` column:

```php
// CORRECT
Schema::create('books', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('tenant_id')->index();
    $table->string('title');
    $table->timestamps();
});

// WRONG — data will not be isolated between tenants
Schema::create('books', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->timestamps();
});
```

### 5. Use `BelongsToTenant` on every tenant-scoped model

```php
use Modularity\Support\Traits\BelongsToTenant;

class Book extends Model
{
    use BelongsToTenant;
    // Automatically: queries scoped to current tenant, tenant_id set on create
}
```

Or extend `ModuleModel` which already includes the trait.

### 6. Slug format is strictly kebab-case

Valid: `library`, `billing-reports`, `crm2`, `my-module`
Invalid: `Library`, `billing_reports`, `My Module`, `CRM`

Regex: `/^[a-z0-9]+(?:-[a-z0-9]+)*$/`

### 7. Dependencies must be installed before the dependent

```json
// module.json for "crm"
{
    "dependencies": ["core-billing"]
}
```

When helping users troubleshoot `DependencyNotInstalledException`, the fix is always:

```bash
php artisan module:install core-billing
php artisan module:install crm
```

### 8. Module config (`Module::config()`) requires a resolved tenant

`Module::config($slug, $key, $default)` returns `$default` when no tenant is set. Don't call it in contexts where `Tenant::id()` is null (unauthenticated routes, console commands without `--tenant`).

---

## Common Tasks

### Create a new module

```bash
php artisan module:make-module Invoicing
php artisan module:make-module Invoicing --livewire
```

### Install and activate for a tenant

```bash
php artisan module:install invoicing
php artisan module:activate invoicing --tenant=1
```

### Check module status

```bash
php artisan module:list
php artisan module:list --tenant=1
php artisan module:status invoicing
```

### Upgrade after adding migrations

```bash
# Add new migration to Modules/Invoicing/database/migrations/
php artisan module:upgrade invoicing
```

### Remove a module

```bash
# Deactivate all tenants first (or use --force):
php artisan module:deactivate invoicing --all-tenants
php artisan module:remove invoicing
# or:
php artisan module:remove invoicing --force
```

---

## Facade Quick Reference

```php
use Modularity\Support\Facades\Module;
use Modularity\Support\Facades\Tenant;

// ── Module checks ──────────────────────────────────────
Module::active('library')                    // bool — current tenant
Module::activeFor('library', $tenantId)      // bool — specific tenant
Module::installed('library')                 // bool — globally installed
Module::discovered('library')               // bool — on filesystem/Composer

// ── Navigation ─────────────────────────────────────────
Module::menu()->add([                        // Register item
    'module'     => 'library',
    'label'      => 'Library',
    'icon'       => 'book-open',
    'route'      => 'library.index',
    'permission' => 'library.view',          // optional
    'order'      => 50,
    'group'      => 'content',
]);
Module::menu()->forTenant($id, $user)        // Collection of MenuItem
Module::menu()->forTenantGrouped($id, $user) // Collection grouped by 'group'

// ── Settings ───────────────────────────────────────────
Module::config('library', 'pagination.per_page', 15)  // mixed

// ── Tenant ─────────────────────────────────────────────
Tenant::set(1)
Tenant::id()          // ?int
Tenant::isSet()       // bool
Tenant::forget()
Tenant::assertSet()   // int or throws TenantNotResolvedException
```

---

## Dependency Injection Reference

These classes are all bound as singletons in the container:

```php
// Orchestrator (use this before raw lifecycle classes)
app(\Modularity\Core\Module\ModuleManager::class)

// Registry and loader
app(\Modularity\Core\Module\ModuleRegistry::class)
app(\Modularity\Core\Module\ModuleLoader::class)

// Tenant context (request-scoped singleton)
app(\Modularity\Core\Tenancy\TenantContext::class)

// Navigation and permissions
app(\Modularity\Core\Navigation\NavigationRegistry::class)
app(\Modularity\Core\Permissions\PermissionRegistry::class)

// Lifecycle operations
app(\Modularity\Core\Lifecycle\ModuleInstaller::class)
app(\Modularity\Core\Lifecycle\ModuleActivator::class)
app(\Modularity\Core\Lifecycle\ModuleDeactivator::class)
app(\Modularity\Core\Lifecycle\ModuleUpgrader::class)
app(\Modularity\Core\Lifecycle\ModuleRemover::class)
```

---

## Events Reference

```php
use Modularity\Events\ModuleInstalled;   // $event->manifest  (ManifestDTO)
use Modularity\Events\ModuleActivated;   // $event->slug, $event->tenantId
use Modularity\Events\ModuleDeactivated; // $event->slug, $event->tenantId
use Modularity\Events\ModuleUpgraded;    // $event->slug, $event->oldVersion, $event->newVersion
use Modularity\Events\ModuleRemoved;     // $event->slug
```

Listen in the host application's `EventServiceProvider`:

```php
protected $listen = [
    \Modularity\Events\ModuleActivated::class => [
        \App\Listeners\ProvisionModuleResources::class,
    ],
];
```

---

## Exceptions Reference

| Exception | Namespace | When raised |
|---|---|---|
| `ModuleNotFoundException` | `Core\Module\Exceptions` | Slug not in registry |
| `ModuleNotInstalledException` | `Core\Module\Exceptions` | Activate before install |
| `ModuleAlreadyInstalledException` | `Core\Module\Exceptions` | Double install |
| `ModuleStillActiveException` | `Core\Module\Exceptions` | Remove with active tenants |
| `DependencyNotInstalledException` | `Core\Module\Exceptions` | Missing dependency at install |
| `InvalidManifestException` | `Core\Module\Exceptions` | Malformed `module.json` |
| `CircularDependencyException` | `Core\Module\Exceptions` | Circular deps in graph |
| `TenantNotResolvedException` | `Core\Tenancy\Exceptions` | `assertSet()` with no tenant |

---

## Database Tables

| Table | Purpose |
|---|---|
| `modularity_installed_modules` | Global install registry |
| `modularity_tenant_modules` | Per-tenant activation + JSON settings |
| `modularity_tenant_module_subscriptions` | Phase 2 billing (unpopulated in Phase 1) |
| `modularity_migration_log` | Per-module migration tracking |

All tables use the prefix from `config('modularity.migrations.table_prefix')`.

---

## Models Reference

```php
\Modularity\Models\InstalledModule        // slug, name, version, status, installed_at
\Modularity\Models\TenantModule           // tenant_id, module_slug, active, settings (JSON)
\Modularity\Models\TenantModuleSubscription // tenant_id, module_slug, status, expires_at
\Modularity\Models\ModuleMigrationLog     // module_slug, migration_file, batch
```

`TenantModule` scopes:

```php
TenantModule::active()->get()
TenantModule::forTenant($tenantId)->get()
TenantModule::forModule($slug)->get()
```

---

## Stub Templates

When `module:make-module` runs, it fills these stubs from `stubs/module/`:

| Stub file | Destination |
|---|---|
| `module.json.stub` | `Modules/<Name>/module.json` |
| `ServiceProvider.stub` | `Modules/<Name>/src/Providers/<Name>ServiceProvider.php` |
| `Controller.stub` | `Modules/<Name>/src/Http/Controllers/<Name>Controller.php` |
| `Model.stub` | `Modules/<Name>/src/Models/<Name>.php` |
| `Event.stub` | `Modules/<Name>/src/Events/<Name>Created.php` |
| `Listener.stub` | `Modules/<Name>/src/Listeners/On<Name>Created.php` |
| `Policy.stub` | `Modules/<Name>/src/Policies/<Name>Policy.php` |
| `migration.create.stub` | `Modules/<Name>/database/migrations/…_create_<name>_table.php` |
| `routes/web.stub` | `Modules/<Name>/routes/web.php` |
| `routes/api.stub` | `Modules/<Name>/routes/api.php` |
| `Http/Livewire/LivewireComponent.stub` | (only with `--livewire`) |
| `resources/views/index.blade.stub` | `Modules/<Name>/resources/views/index.blade.php` |
| `resources/views/livewire/component.blade.stub` | (only with `--livewire`) |

Stubs use `{{PascalName}}` and `{{kebab-slug}}` as placeholders.

To customize scaffolding output:

```bash
php artisan vendor:publish --tag=modularity-stubs
# Stubs are copied to stubs/modularity/ — edit them freely
```

---

## Configuration Reference

```php
// config/modularity.php
[
    'modules_path'      => base_path('Modules'),   // Local module root
    'modules_namespace' => 'Modules',               // PSR-4 root namespace
    'version'           => '1.0.0',                 // Core version for compatibility checks

    'cache' => [
        'enabled' => env('MODULARITY_CACHE', true), // Disable during development
        'ttl'     => 3600,
        'store'   => null,                          // null = default cache driver
    ],

    'tenancy' => [
        'resolvers' => ['subdomain', 'domain', 'header', 'session'],
        'column'    => 'tenant_id',
        'model'     => env('MODULARITY_TENANT_MODEL', null), // FQCN of host Tenant model
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
        'driver' => env('MODULARITY_PERMISSION_DRIVER', 'gate'), // gate | spatie | null
    ],
]
```

---

## Patterns to Recommend

### Checking module access in a controller

```php
public function index(): View
{
    abort_unless(Module::active('library'), 403);
    // or use middleware: ->middleware('module.active:library')
}
```

### Blade conditional rendering

```blade
@if(Module::active('library'))
    <x-library::book-list />
@endif
```

### Listening to lifecycle events in a module

```php
// In the module's ServiceProvider or a separate listener:
protected array $listen = [
    \Modularity\Events\ModuleActivated::class => [
        \Modules\Library\Listeners\ProvisionLibraryData::class,
    ],
];
```

The listener receives the event object, but will only be registered when the module itself is active. For bootstrapping logic that must run on first activation, listen in the host application's `EventServiceProvider` instead.

### Per-tenant module settings

```php
// Read:
$limit = Module::config('library', 'max_books_per_user', 100);

// Write (in an admin controller):
use Modularity\Models\TenantModule;

TenantModule::forTenant(Tenant::id())
    ->forModule('library')
    ->update(['settings->max_books_per_user' => 50]);
```

### Testing with tenant context

```php
use Modularity\Tests\TestCase;
use Modularity\Support\Facades\Tenant;

class BookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::set(1);
    }

    protected function tearDown(): void
    {
        Tenant::forget();
        parent::tearDown();
    }
}
```

### Programmatic module management (admin UI)

```php
use Modularity\Core\Lifecycle\ModuleInstaller;
use Modularity\Core\Lifecycle\ModuleActivator;

class AdminModuleController extends Controller
{
    public function __construct(
        private readonly ModuleInstaller $installer,
        private readonly ModuleActivator $activator,
    ) {}

    public function install(string $slug): RedirectResponse
    {
        $this->installer->install($slug);
        return back()->with('success', "Module {$slug} installed.");
    }

    public function activate(string $slug, int $tenantId): RedirectResponse
    {
        $this->activator->activate($slug, $tenantId);
        return back()->with('success', "Module {$slug} activated for tenant {$tenantId}.");
    }
}
```

---

## Anti-Patterns to Avoid

| Anti-pattern | Problem | Fix |
|---|---|---|
| Extending `ModuleServiceProvider` without setting `$slug` | Runtime error on boot | Always declare `protected string $slug = 'my-slug'` |
| Module table without `tenant_id` column | Data leaks between tenants | Add `$table->unsignedBigInteger('tenant_id')->index()` |
| Using `Module::active()` inside module routes | Redundant — routes don't load if inactive | Remove the check; trust the lifecycle |
| Accessing `Tenant::id()` in CLI commands without setting it | Returns null silently | Pass `--tenant` option or call `Tenant::set()` first |
| Calling `Module::config()` on unauthenticated routes | Returns default silently | Guard with `Tenant::isSet()` check first |
| CamelCase or snake_case slugs | `InvalidManifestException` | Use kebab-case only: `my-module` |
| Installing a module without its dependencies | `DependencyNotInstalledException` | Install in dependency order |
| Manually creating `InstalledModule` records | Bypasses lifecycle (no migrations, no events) | Always use `ModuleInstaller::install()` |
| Rolling back module migrations manually | May break other modules that depend on tables | Never roll back; remove data via seeders or custom commands |
| Putting unconditional boot logic in `ModuleServiceProvider::boot()` | Runs only when active — may never run | Move to `register()` or use application-level event listeners |

---

## Composer Package Detection

A Composer-installed package is auto-discovered as a module if its `composer.json` declares:

```json
{
    "extra": {
        "modularity": {
            "module": true
        }
    }
}
```

The package must also ship a `module.json` at its package root. Modularity reads `vendor/composer/installed.json` to find such packages.

---

## Phase 2 Marketplace Contracts

These interfaces are implemented by Null Objects in Phase 1 and can be replaced via the service container:

```php
Modularity\Marketplace\Contracts\MarketplaceClientInterface
    → fetchAvailable(): array
    → fetchModule(string $slug): ?array

Modularity\Marketplace\Contracts\LicenseVerifierInterface
    → verify(string $slug, int $tenantId): bool

Modularity\Marketplace\Contracts\SubscriptionManagerInterface
    → isSubscribed(string $slug, int $tenantId): bool
    → getSubscription(string $slug, int $tenantId): ?TenantModuleSubscription
```

To implement Phase 2, bind real implementations in the host app:

```php
$this->app->bind(
    \Modularity\Marketplace\Contracts\MarketplaceClientInterface::class,
    \App\Services\RemoteMarketplaceClient::class,
);
```

---

## File Ownership Summary

| What you are doing | Files to touch |
|---|---|
| Creating a new module | `Modules/<Name>/module.json`, `src/Providers/<Name>ServiceProvider.php`, `database/migrations/`, `routes/`, `resources/views/` |
| Adding a menu item | `registerModuleNavigation()` in the module's ServiceProvider |
| Adding a tenant-scoped model | Extend `ModuleModel` or use `BelongsToTenant` trait |
| Wiring module events | `protected array $listen` in the module's ServiceProvider |
| Registering Livewire components | `protected array $livewireComponents` in the ServiceProvider |
| Changing tenant resolution strategy | `config/modularity.php` → `tenancy.resolvers` |
| Changing permission driver | `.env` → `MODULARITY_PERMISSION_DRIVER` |
| Adding module settings | `TenantModule::settings` JSON column via `Module::config()` |
| Programmatic lifecycle management | Inject lifecycle classes (`ModuleInstaller`, etc.) |
| Writing tests | Extend `Modularity\Tests\TestCase`, use `Tenant::set()` |
