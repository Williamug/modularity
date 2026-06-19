# GitHub Copilot Instructions — modularity/core

This is `modularity/core`, a Laravel package (PHP ^8.2, Laravel >=11.0) that transforms any Laravel application into a modular, multi-tenant SaaS platform. The complete developer reference is in `README.md`. The full AI agent guide (architecture, patterns, anti-patterns) is in `AGENTS.md`. Read both before generating code.

---

## Repository Map

```
src/
├── Console/Commands/          # 9 Artisan commands (module:*)
├── Core/
│   ├── Lifecycle/             # ModuleInstaller, Activator, Deactivator, Upgrader, Remover
│   ├── Module/                # ModuleRegistry, ModuleLoader, ModuleManifest, ModuleManager
│   │                          # ManifestDTO, DependencyGraph, MigrationRunner
│   ├── Navigation/            # NavigationRegistry, MenuItem, MenuGroup
│   ├── Permissions/           # PermissionRegistry + Gate/Spatie/Null drivers
│   └── Tenancy/               # TenantContext, TenantResolver, TenantScope + 4 resolvers
├── Events/                    # ModuleInstalled, Activated, Deactivated, Upgraded, Removed
├── Http/Middleware/           # ResolveTenantMiddleware (alias: resolve.tenant)
├── Models/                    # InstalledModule, TenantModule, TenantModuleSubscription,
│                              # ModuleMigrationLog
└── Support/
    ├── Abstracts/             # ModuleServiceProvider (base), ModuleModel (base)
    ├── Facades/               # Module, Tenant
    └── Traits/                # BelongsToTenant
config/modularity.php
database/migrations/           # 4 infrastructure tables (modularity_*)
stubs/module/                  # Scaffolding templates for module:make-module
tests/
```

---

## Non-Negotiable Rules

Apply these to every code suggestion in this repo:

### 1 — `$slug` is required on every ModuleServiceProvider

```php
// ALWAYS include this — its absence causes a runtime error
class InvoicingServiceProvider extends ModuleServiceProvider
{
    protected string $slug = 'invoicing'; // must match slug in module.json exactly
}
```

### 2 — Every tenant-scoped table needs `tenant_id`

```php
Schema::create('invoices', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('tenant_id')->index(); // required
    // ... other columns
    $table->timestamps();
});
```

### 3 — Every tenant-scoped model uses `BelongsToTenant` or extends `ModuleModel`

```php
use Modularity\Support\Traits\BelongsToTenant;

class Invoice extends Model
{
    use BelongsToTenant; // auto-scopes queries and sets tenant_id on create
}

// or simply:
class Invoice extends \Modularity\Support\Abstracts\ModuleModel {}
```

### 4 — Slug format: kebab-case only

Valid: `invoicing`, `billing-reports`, `crm2`
Invalid: `Invoicing`, `billing_reports`, `My Module`
Regex: `/^[a-z0-9]+(?:-[a-z0-9]+)*$/`

### 5 — Module boot is conditional — do not add unconditional logic there

`ModuleServiceProvider::boot()` calls `moduleIsActive()` first and returns early if the module is not active for the current tenant. Routes, views, listeners, and navigation are never loaded for inactive modules.

```php
// WRONG — register() runs unconditionally; boot() does not
public function register(): void
{
    // Bind module-specific services here (always runs)
}

public function boot(): void
{
    // Everything here runs ONLY when module is active for current tenant
    // parent::boot() handles routes, views, listeners, and navigation for you
    parent::boot();
}
```

### 6 — Install order matters; migrations are never rolled back

```bash
# Install dependencies first:
php artisan module:install core-billing
php artisan module:install invoicing   # depends on core-billing

# Never suggest migrate:rollback for module migrations — data is preserved intentionally
```

### 7 — `Module::config()` returns the default when no tenant is set

```php
// Guard in contexts where a tenant may not be resolved:
if (Tenant::isSet()) {
    $limit = Module::config('invoicing', 'max_items', 100);
}
```

### 8 — Establishing the current tenant is the host app's job

Prefer setting the tenant explicitly once the request is authenticated — don't assume the resolver chain is configured:

```php
Tenant::set($user->tenant_id); // your own middleware / auth pipeline
```

The optional `ResolveTenantMiddleware` defaults to the `session` resolver only. `subdomain`, `domain`, and `header` are **opt-in**, require `MODULARITY_TENANT_MODEL`, and read attacker-controllable input — a resolved tenant ID is **identity, not authorization**. Never suggest trusting it without confirming the authenticated user belongs to that tenant.

---

## Facades

```php
use Modularity\Support\Facades\Module;
use Modularity\Support\Facades\Tenant;

// Module state
Module::active(string $slug): bool               // current tenant
Module::activeFor(string $slug, int $id): bool   // explicit tenant
Module::installed(string $slug): bool
Module::discovered(string $slug): bool

// Navigation
Module::menu()->add([
    'module'     => 'invoicing',
    'label'      => 'Invoices',
    'icon'       => 'document-text',
    'route'      => 'invoicing.index',
    'permission' => 'invoicing.view',   // optional — hides item if user lacks it
    'order'      => 50,                 // ascending sort (default 100)
    'group'      => 'finance',          // default 'general'
]);
Module::menu()->forTenant(int $id, ?object $user): Collection
Module::menu()->forTenantGrouped(int $id, ?object $user): Collection

// Per-tenant settings (stored as JSON in modularity_tenant_modules.settings)
Module::config(string $slug, string $key, mixed $default = null): mixed

// Registry access
Module::registry(): ModuleRegistry

// Tenant context
Tenant::set(int $id): void
Tenant::id(): ?int
Tenant::isSet(): bool
Tenant::forget(): void
Tenant::assertSet(): int  // throws TenantNotResolvedException if null
```

---

## Module Manifest (`module.json`)

```json
{
    "name": "Invoicing",
    "slug": "invoicing",
    "version": "1.0.0",
    "description": "Invoice and payment management",
    "providers": [
        "Modules\\Invoicing\\Providers\\InvoicingServiceProvider"
    ],
    "permissions": [
        "invoicing.view",
        "invoicing.create",
        "invoicing.update",
        "invoicing.delete"
    ],
    "dependencies": ["core-billing"],
    "compatibility": "^1.0"
}
```

Required fields: `name`, `slug`, `version`, `providers`

---

## Module Service Provider Template

```php
namespace Modules\Invoicing\Providers;

use Modularity\Support\Abstracts\ModuleServiceProvider;
use Modularity\Support\Facades\Module;

class InvoicingServiceProvider extends ModuleServiceProvider
{
    protected string $slug = 'invoicing';      // required
    protected string $version = '1.0.0';

    protected array $listen = [
        \Modules\Invoicing\Events\InvoiceCreated::class => [
            \Modules\Invoicing\Listeners\OnInvoiceCreated::class,
        ],
    ];

    protected array $livewireComponents = [
        'invoicing-list' => \Modules\Invoicing\Http\Livewire\InvoiceList::class,
    ];

    protected function registerModuleNavigation(): void
    {
        Module::menu()->add([
            'module'     => 'invoicing',
            'label'      => 'Invoices',
            'icon'       => 'document-text',
            'route'      => 'invoicing.index',
            'permission' => 'invoicing.view',
            'order'      => 40,
            'group'      => 'finance',
        ]);
    }
}
```

---

## Artisan Commands

```bash
php artisan module:make-module <Name> [--livewire]     # scaffold
php artisan module:install <slug> [--path=]            # global install (runs migrations)
php artisan module:activate <slug> --tenant=<id>       # activate for a tenant
php artisan module:deactivate <slug> --tenant=<id>     # deactivate for a tenant
php artisan module:deactivate <slug> --all-tenants     # deactivate for all
php artisan module:upgrade <slug>                      # run pending module migrations
php artisan module:remove <slug> [--force]             # remove (--force auto-deactivates)
php artisan module:list [--tenant=<id>]                # list all modules + status
php artisan module:status <slug>                       # detailed status
```

---

## Lifecycle Classes (via DI)

```php
use Modularity\Core\Lifecycle\ModuleInstaller;
use Modularity\Core\Lifecycle\ModuleActivator;
use Modularity\Core\Lifecycle\ModuleDeactivator;
use Modularity\Core\Lifecycle\ModuleUpgrader;
use Modularity\Core\Lifecycle\ModuleRemover;

// All are bound as singletons — inject in constructors freely
$installer->install(string $slug, ?string $path = null): InstalledModule
$activator->activate(string $slug, int $tenantId): TenantModule
$deactivator->deactivate(string $slug, int $tenantId): void
$deactivator->deactivateAll(string $slug): void
$upgrader->upgrade(string $slug): InstalledModule
$remover->remove(string $slug, bool $force = false): void
```

---

## Events

```php
\Modularity\Events\ModuleInstalled::class   // ->manifest  (ManifestDTO)
\Modularity\Events\ModuleActivated::class   // ->slug, ->tenantId
\Modularity\Events\ModuleDeactivated::class // ->slug, ->tenantId
\Modularity\Events\ModuleUpgraded::class    // ->slug, ->oldVersion, ->newVersion
\Modularity\Events\ModuleRemoved::class     // ->slug
```

---

## Exceptions

```
Modularity\Core\Module\Exceptions\ModuleNotFoundException
Modularity\Core\Module\Exceptions\ModuleNotInstalledException
Modularity\Core\Module\Exceptions\ModuleAlreadyInstalledException
Modularity\Core\Module\Exceptions\ModuleStillActiveException
Modularity\Core\Module\Exceptions\DependencyNotInstalledException
Modularity\Core\Module\Exceptions\InvalidManifestException
Modularity\Core\Module\Exceptions\CircularDependencyException
Modularity\Core\Tenancy\Exceptions\TenantNotResolvedException
```

---

## Database Tables

| Table | Key columns |
|---|---|
| `modularity_installed_modules` | `slug` (unique), `name`, `version`, `status` |
| `modularity_tenant_modules` | `tenant_id`, `module_slug`, `active`, `settings` (JSON) |
| `modularity_tenant_module_subscriptions` | `tenant_id`, `module_slug`, `status`, `expires_at` |
| `modularity_migration_log` | `module_slug`, `migration_file`, `batch` |

---

## Anti-Patterns — Never Suggest These

| Pattern | Why it's wrong |
|---|---|
| `ModuleServiceProvider` without `$slug` | Runtime error |
| Module table without `tenant_id` | Data leaks across tenants |
| `Module::active()` guard inside module routes | Routes don't load when inactive — check is redundant |
| `php artisan migrate:rollback` for module migrations | Migrations are intentionally permanent |
| PascalCase or snake_case slugs | Fails manifest validation |
| Installing a module before its dependencies | Throws `DependencyNotInstalledException` |
| Manually creating `InstalledModule` records | Bypasses migrations, permissions, and events |
| Calling `Module::config()` without checking `Tenant::isSet()` | Returns default silently in CLI/unauthenticated contexts |
| Unconditional logic in `ModuleServiceProvider::boot()` | Only runs when module is active |

---

## Testing

Extend `Modularity\Tests\TestCase`:

```php
use Modularity\Tests\TestCase;
use Modularity\Support\Facades\Tenant;

class InvoiceTest extends TestCase
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

The base `TestCase` uses SQLite in-memory, disables cache, and sets permissions driver to `null` (all checks pass).
