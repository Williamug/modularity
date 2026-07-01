# Modularity Core

Turn any Laravel 11+ application into a modular, multi-tenant SaaS platform. Ship features as self-contained **modules** that can be installed once and switched on or off per tenant — with isolated data, navigation, permissions, and migrations.

```bash
composer require modularity/core
```

- **Module discovery** — from a local `Modules/` directory or Composer packages
- **Full lifecycle** — install → activate (per tenant) → upgrade → deactivate → remove
- **Multi-tenancy** — automatic Eloquent scoping; you stay in control of *who* the tenant is
- **Navigation** — tenant-aware, permission-filtered menu registry
- **Permissions** — pluggable drivers (Gate, Spatie, Null), or bring your own
- **Dependency graph** — modules declare dependencies; circular ones are rejected
- **Marketplace-ready** — billing/subscription contracts already defined (Phase 2)

> **AI assistants:** authoritative guidance for code generation lives in [`AGENTS.md`](AGENTS.md), [`.cursorrules`](.cursorrules), and [`.github/copilot-instructions.md`](.github/copilot-instructions.md).

---

## Table of Contents

1. [Requirements](#requirements)
2. [How It Works](#how-it-works)
3. [Installation](#installation)
4. [Quick Start](#quick-start)
5. [Configuration](#configuration)
6. [Core Concepts](#core-concepts)
   - [Module Manifest](#module-manifest)
   - [Module Lifecycle](#module-lifecycle)
   - [Multi-Tenancy](#multi-tenancy)
   - [Navigation](#navigation)
   - [Permissions](#permissions)
7. [Authoring a Module](#authoring-a-module)
8. [Artisan Commands](#artisan-commands)
9. [Facade API](#facade-api)
10. [Dependency Injection API](#dependency-injection-api)
11. [Events](#events)
12. [Exceptions](#exceptions)
13. [Database Schema](#database-schema)
14. [Testing](#testing)
15. [Publishing Assets](#publishing-assets)
16. [Marketplace (Phase 2)](#marketplace-phase-2)
17. [Troubleshooting](#troubleshooting)
18. [License](#license)

---

## Requirements

| Dependency | Version | Notes |
|---|---|---|
| PHP | `^8.2` | |
| Laravel / Illuminate | `>=11.0` | |
| [composer/semver](https://github.com/composer/semver) | `^3.0` | Installed automatically |
| [spatie/laravel-permission](https://github.com/spatie/laravel-permission) | Optional | Only when `permissions.driver = spatie` |
| [livewire/livewire](https://livewire.laravel.com) | Optional | Only when scaffolding/registering Livewire components |

---

## How It Works

Modularity has three audiences. Knowing which layer you're in keeps the API small.

```
┌────────────────────────────────────────────────────────────┐
│  Module authors                                            │
│  Extend ModuleServiceProvider + ship module.json           │
│  Extend ModuleModel (or use BelongsToTenant) for data      │
└──────────────────────────┬─────────────────────────────────┘
                           │ build modules that the host app turns on
┌──────────────────────────▼─────────────────────────────────┐
│  Host application (you)                                    │
│  Tenant::set()        Module::active()    Module::menu()    │
│  php artisan module:install / module:activate              │
└──────────────────────────┬─────────────────────────────────┘
                           │ backed by
┌──────────────────────────▼─────────────────────────────────┐
│  Package internals                                         │
│  ModuleRegistry · ModuleLoader · TenantContext · TenantScope│
│  PermissionRegistry · NavigationRegistry · Lifecycle managers           │
└────────────────────────────────────────────────────────────┘
```

The whole system rests on two ideas:

1. **A module is installed once, then switched on *per tenant*.** Installing runs its migrations and registers its permissions. Once installed, a module's routes, views, and navigation are registered on **every** request (the tenant isn't known yet at boot) — and access is gated *per tenant at request time*: the `module.active` middleware blocks tenants that haven't activated it, and `Module::menu()->forTenant()` only shows modules a tenant has switched on.
2. **You decide who the tenant is.** Modularity gives you a request-scoped `TenantContext` and automatic query scoping. It does **not** assume how your app authenticates tenants — see [Multi-Tenancy](#multi-tenancy).

---

## Installation

```bash
composer require modularity/core
```

Laravel auto-discovery registers the service provider and the `Module` / `Tenant` facades. If you have auto-discovery disabled, register them manually in `config/app.php`:

```php
'providers' => [
    Modularity\ModularityServiceProvider::class,
],
'aliases' => [
    'Module' => Modularity\Support\Facades\Module::class,
    'Tenant' => Modularity\Support\Facades\Tenant::class,
],
```

Publish and run the infrastructure migrations (creates four `modularity_*` tables):

```bash
php artisan vendor:publish --tag=modularity-migrations
php artisan migrate
```

Publish the config file (optional, but recommended):

```bash
php artisan vendor:publish --tag=modularity-config
```

### Tenant resolution middleware (optional)

The package ships a `ResolveTenantMiddleware` (alias `resolve.tenant`) that runs the configured resolver chain on each request. **Most apps don't need it** — calling `Tenant::set()` in your own auth flow is simpler and safer (see [Multi-Tenancy](#multi-tenancy)). If you do want the built-in chain, add the alias to a route group:

```php
// bootstrap/app.php (Laravel 11+)
->withMiddleware(function (Middleware $middleware) {
    $middleware->appendToGroup('web', \Modularity\Http\Middleware\ResolveTenantMiddleware::class);
})
```

The alias `resolve.tenant` is registered automatically, so you can also attach it per-route: `->middleware('resolve.tenant')`.

### Gating module access per tenant

Because a module's routes are registered for **every** installed module, you gate who may reach them with the package's `module.active` middleware (alias registered automatically). It returns **404** unless the module is active for the current tenant:

```php
// In a module's routes/web.php — the scaffolded stub already does this
Route::middleware(['web', 'auth', 'module.active:library'])
    ->prefix('library')->name('library.')->group(function () {
        // ...
    });
```

The current tenant must already be set on the request (via your own middleware calling `Tenant::set()`, or `resolve.tenant`). See [Multi-Tenancy](#multi-tenancy) and the host-integration walkthrough in [`INTEGRATION.md`](INTEGRATION.md).

---

## Quick Start

A complete loop — scaffold a module, install it, and switch it on for a tenant.

```bash
# 1. Scaffold a module under Modules/Blog/
php artisan module:make-module Blog

# 2. Install it globally (runs the module's migrations, registers its permissions)
php artisan module:install blog

# 3. Activate it for tenant #1
php artisan module:activate blog --tenant=1

# 4. Confirm
php artisan module:list --tenant=1
php artisan module:status blog
```

In your application, set the current tenant (in your auth flow or your own middleware), then everything scopes automatically:

```php
use Modularity\Support\Facades\Tenant;
use Modularity\Support\Facades\Module;

Tenant::set(auth()->user()->tenant_id);

Module::active('blog');                 // true — module loaded for this tenant
Module::menu()->forTenant(Tenant::id(), auth()->user()); // visible menu items
```

---

## Configuration

`config/modularity.php` (published via `--tag=modularity-config`):

```php
return [
    // Where local modules live; each subdirectory must contain a module.json
    'modules_path'      => base_path('Modules'),

    // PSR-4 namespace prefix for scaffolded modules
    'modules_namespace' => 'Modules',

    // Core version used for module compatibility checks
    'version' => '1.0.0',

    'cache' => [
        'enabled' => env('MODULARITY_CACHE', true),
        'ttl'     => 3600,   // seconds
        'store'   => null,   // null = default cache driver
    ],

    'tenancy' => [
        // Built-in resolvers tried in order until one returns a tenant ID.
        // Default is 'session' only — see the Multi-Tenancy section before
        // enabling subdomain/domain/header (they are opt-in and security-sensitive).
        'resolvers' => ['session'],
        // Column name used across all Modularity tables
        'column'    => 'tenant_id',
        // FQCN of your app's Tenant model (required by subdomain/domain/header resolvers)
        'model'     => env('MODULARITY_TENANT_MODEL', null),
        // Fail closed: throw when a BelongsToTenant model is queried with no tenant set
        // (the console is exempt). Off by default; see Multi-Tenancy.
        'strict'    => env('MODULARITY_TENANCY_STRICT', false),
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
        // 'gate' (default) | 'spatie' | 'null' | a custom driver FQCN
        'driver' => env('MODULARITY_PERMISSION_DRIVER', 'gate'),
    ],
];
```

**Environment variables:**

```dotenv
MODULARITY_CACHE=true
MODULARITY_PERMISSION_DRIVER=gate          # gate | spatie | null
MODULARITY_TENANT_MODEL=App\Models\Tenant  # required only for subdomain/domain/header resolvers
MODULARITY_MARKETPLACE_URL=
MODULARITY_MARKETPLACE_KEY=
```

> **Tip:** during development set `MODULARITY_CACHE=false` so install/activate changes are reflected immediately without flushing the registry cache.

---

## Core Concepts

### Module Manifest

Every module declares itself in a `module.json` file at its root:

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
| `slug` | Yes | Unique kebab-case identifier (e.g. `library`, `billing-reports`) |
| `version` | Yes | SemVer string |
| `providers` | Yes | Fully-qualified service provider class names |
| `description` | No | Short description |
| `permissions` | No | Permission strings registered with the driver at install time |
| `dependencies` | No | Module slugs that must be installed first |
| `compatibility` | No | SemVer constraint checked against the core `version` |

> **Slug format** must match `/^[a-z0-9]+(?:-[a-z0-9]+)*$/` — lowercase letters, digits, and single hyphens. `Library`, `my_module`, and `My Module` are all invalid.

---

### Module Lifecycle

```
discover → install → activate (per-tenant) → [upgrade] → deactivate → remove
```

| State | Stored in | Scope |
|---|---|---|
| **Discovered** | Filesystem / Composer (in memory) | Global |
| **Installed** | `modularity_installed_modules` | Global |
| **Active** | `modularity_tenant_modules` | Per-tenant |

**Transitions:**

```bash
php artisan module:install    library                  # Discovered → Installed (runs migrations)
php artisan module:activate   library --tenant=1        # Installed → Active for tenant 1
php artisan module:upgrade    library                   # Runs pending migrations, bumps version
php artisan module:deactivate library --tenant=1        # Active → Inactive for tenant 1
php artisan module:remove     library                   # Installed → Removed (migrations NOT rolled back)
```

**What `install` does:**
1. Validates every declared `dependency` is already installed
2. Runs the module's migrations from `database/migrations/`
3. Registers declared permissions with the active permission driver
4. Creates an `InstalledModule` record
5. Dispatches `ModuleInstalled`

**What `activate` does:**
1. Creates or updates a `TenantModule` record with `active = true`
2. Invalidates the registry cache for that tenant
3. Dispatches `ModuleActivated`

**Key invariants:**
- A module **cannot be activated** unless it is installed.
- A module **cannot be removed** while any tenant still has it active — pass `--force` to auto-deactivate first.
- **Migrations are never rolled back.** Even after `module:remove`, tenant data is preserved on purpose.
- `install` is **idempotent** — safe to run repeatedly.

---

### Multi-Tenancy

Modularity isolates module data per tenant by reading a single value — `TenantContext` — and scoping Eloquent queries to it. **Establishing that value is your application's job**, because only your app knows how a request maps to a tenant safely.

#### Recommended: set the tenant yourself

The simplest and safest approach is to set the tenant once you've authenticated the request:

```php
use Modularity\Support\Facades\Tenant;

// In your own middleware, a route, or your auth pipeline:
Tenant::set($user->tenant_id);
```

That's all the package needs. Everything downstream — query scoping, `Module::active()`, navigation, settings — reads from `TenantContext`.

#### Optional: built-in resolver chain

If you'd rather have the package resolve the tenant from the request, register `ResolveTenantMiddleware` (see [Installation](#tenant-resolution-middleware-optional)). It runs the resolvers listed in `tenancy.resolvers`, in order, and the **first non-null result wins**. The context is automatically cleared after the response to prevent bleed across queue/Octane workers.

| Resolver | Source | Default? |
|---|---|---|
| `session` | `modularity_tenant_id` in the session | ✅ enabled by default |
| `subdomain` | First subdomain segment, looked up in your `Tenant` model | ⚠️ opt-in |
| `domain` | Full hostname, looked up in your `Tenant` model | ⚠️ opt-in |
| `header` | `X-Tenant-ID` request header, verified against your `Tenant` model | ⚠️ opt-in |

> **⚠️ Security:** `subdomain`, `domain`, and `header` read attacker-controllable input. A resolved tenant ID is **identity, not authorization** — always confirm the authenticated user actually belongs to that tenant before trusting it. These resolvers require `MODULARITY_TENANT_MODEL` to be set so the value can be validated against a real record. The default chain is `['session']` precisely because it is host-controlled.

You can also list a custom resolver by FQCN (any class implementing `TenantResolverInterface`):

```php
'resolvers' => ['session', \App\Tenancy\JwtTenantResolver::class],
```

#### Automatic Eloquent scoping

Apply `BelongsToTenant` to any model to scope every query to the current tenant and set `tenant_id` automatically on insert:

```php
use Modularity\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Book extends Model
{
    use BelongsToTenant; // queries filtered by tenant_id; tenant_id set on create
}
```

Module models can extend `ModuleModel`, which already includes the trait.

> **⚠️ Fail-open by default.** When **no** tenant is set, `BelongsToTenant` queries are *unscoped* — they return every tenant's rows rather than none. Forgetting `Tenant::set()` therefore silently disables isolation. For production, enable **strict mode** to fail closed instead:
>
> ```dotenv
> MODULARITY_TENANCY_STRICT=true
> ```
>
> A `BelongsToTenant` query with no tenant set then throws `TenantNotResolvedException`. The console is exempt, so migrations, seeders, and maintenance commands still run unscoped.

#### Reading tenant context

```php
use Modularity\Support\Facades\Tenant;

Tenant::id();        // ?int — current tenant, or null if not set
Tenant::isSet();     // bool
Tenant::assertSet(); // int — throws TenantNotResolvedException when null
Tenant::forget();    // clear the context
```

---

### Navigation

Modules register menu items during boot. The registry returns only items whose module is active for the tenant and whose permission (if any) the user holds.

**Register an item** (typically in your provider's `registerModuleNavigation()`):

```php
use Modularity\Support\Facades\Module;

Module::menu()->add([
    'module'     => 'library',     // owning module slug (used for the active check)
    'label'      => 'Library',
    'icon'       => 'book-open',
    'route'      => 'library.index',
    'permission' => 'library.view', // optional — item hidden if the user lacks it
    'order'      => 50,             // ascending; lower shows first (default 100)
    'group'      => 'content',      // default 'general'
]);
```

**Render in a Blade view:**

```php
use Modularity\Support\Facades\Module;
use Modularity\Support\Facades\Tenant;

// Flat list, sorted by 'order':
$items  = Module::menu()->forTenant(Tenant::id(), auth()->user());

// Grouped by 'group':
$groups = Module::menu()->forTenantGrouped(Tenant::id(), auth()->user());
```

**MenuItem fields:**

| Field | Type | Default | Description |
|---|---|---|---|
| `module` | `string` | — | Owning module slug |
| `label` | `string` | — | Display text |
| `route` | `string` | — | Named Laravel route |
| `icon` | `string` | `null` | Icon identifier (e.g. a Heroicons name) |
| `permission` | `string` | `null` | Gate ability; item hidden if the user lacks it |
| `order` | `int` | `100` | Sort order (ascending) |
| `group` | `string` | `general` | Group label for grouped rendering |
| `children` | `array` | `[]` | Nested menu items |

---

### Permissions

Permissions declared in `module.json` are registered automatically at install time through a pluggable driver.

| Driver | Config value | Requires |
|---|---|---|
| Laravel Gate | `gate` (default) | Nothing extra |
| Spatie Permission | `spatie` | `spatie/laravel-permission` |
| Null (no-op) | `null` | Nothing — all checks return `true` (useful in tests) |
| Custom | any FQCN | A class implementing `PermissionDriverInterface` |

Set the driver via `MODULARITY_PERMISSION_DRIVER`. To use your own system, point the config value at a class implementing `Modularity\Core\Permissions\Contracts\PermissionDriverInterface` — it's resolved from the container, so it may declare its own dependencies.

```php
// Check via the registry:
Module::permissions()->userCan($user, 'library.view');

// Or use standard Laravel Gate / policies (Gate driver registers abilities for you):
$this->authorize('library.view');
Gate::allows('library.create');
```

---

## Authoring a Module

### Scaffold a new module

```bash
php artisan module:make-module Library
php artisan module:make-module Library --livewire   # also scaffold a Livewire component
```

This generates `Modules/Library/`:

```
Modules/Library/
├── module.json
├── src/
│   ├── Providers/
│   │   └── LibraryServiceProvider.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── LibraryController.php
│   │   └── Livewire/                 (with --livewire)
│   │       └── LibraryComponent.php
│   ├── Models/
│   │   └── Library.php
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

Every module provides a service provider that extends `ModuleServiceProvider`. The base class handles the heavy lifting — routes, views, listeners, Livewire components, and navigation are wired up automatically for **every installed module**, on every request. (Per-tenant access is enforced separately, at request time, by the `module.active` middleware — not in the provider.)

```php
namespace Modules\Library\Providers;

use Modularity\Support\Abstracts\ModuleServiceProvider;
use Modularity\Support\Facades\Module;

class LibraryServiceProvider extends ModuleServiceProvider
{
    // REQUIRED — must match the slug in module.json exactly.
    protected string $slug = 'library';

    protected string $version = '1.0.0';

    // Event → Listener wiring (registered only while the module is active)
    protected array $listen = [
        \Modules\Library\Events\LibraryCreated::class => [
            \Modules\Library\Listeners\OnLibraryCreated::class,
        ],
    ];

    // Livewire component aliases (registered only while the module is active)
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

> **Important:** the base `boot()` registers routes/views/navigation unconditionally (the tenant isn't known yet at boot). What makes a module *switchable* is the `module.active:<slug>` middleware on its routes plus per-tenant menu filtering — not the provider. Put container bindings in `register()` as usual.

### Tenant-scoped models

Extend `ModuleModel` (which already uses `BelongsToTenant`):

```php
namespace Modules\Library\Models;

use Modularity\Support\Abstracts\ModuleModel;

class Book extends ModuleModel
{
    protected $fillable = ['title', 'author', 'isbn'];
    // tenant_id is set automatically on create and filtered on every query
}
```

…or apply the trait to any existing model:

```php
use Modularity\Support\Traits\BelongsToTenant;

class Book extends Model
{
    use BelongsToTenant;
}
```

### Routes

Route files are auto-loaded for every installed module — no manual registration. Gate them per tenant with the `module.active:<slug>` middleware (the scaffolded stub already does this); no need to re-check `Module::active()` inside them:

```php
// routes/web.php — wrapped in the 'web' middleware group
Route::middleware(['auth', 'module.active:library'])->group(function () {
    Route::get('/library', [LibraryController::class, 'index'])->name('library.index');
    Route::get('/library/{book}', [LibraryController::class, 'show'])->name('library.show');
});
```

```php
// routes/api.php — wrapped in the 'api' group and prefixed with /api
Route::middleware(['auth:sanctum'])->group(function () {
    Route::apiResource('books', BookApiController::class);
});
```

### Views

Views in `resources/views/` are namespaced under the module slug:

```php
return view('library::index', compact('books'));
return view('library::books.show', compact('book'));
```

### Migrations

Place migrations in `database/migrations/` (relative to `module.json`). They run at `module:install` and are tracked in `modularity_migration_log`. Always include a `tenant_id` column on tenant-scoped tables:

```php
// database/migrations/2024_01_01_000001_create_books_table.php
return new class extends Migration {
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index(); // required for isolation
            $table->string('title');
            $table->string('author');
            $table->timestamps();
        });
    }
};
```

### Per-tenant module settings

Settings are stored as JSON in `modularity_tenant_modules.settings`:

```php
// Read with a default (returns the default when no tenant is set):
$pageSize = Module::config('library', 'pagination.per_page', 15);

// Write (e.g. in an admin controller):
use Modularity\Models\TenantModule;

TenantModule::forTenant(Tenant::id())
    ->forModule('library')
    ->update(['settings->pagination->per_page' => 25]);
```

### Distributing a module via Composer

To ship a module as a Composer package, add this to its `composer.json` and include a `module.json` at the package root:

```json
{
    "extra": {
        "modularity": {
            "module": true
        }
    }
}
```

Modularity reads `vendor/composer/installed.json` and discovers such packages alongside local modules.

---

## Artisan Commands

| Command | Purpose |
|---|---|
| `module:make-module <Name> [--path=] [--livewire]` | Scaffold a new module directory |
| `module:make-livewire <slug> <ComponentName>` | Scaffold a Livewire component in a module |
| `module:install <slug> [--path=]` | Install globally (runs migrations, registers permissions) |
| `module:activate <slug> --tenant=<id>` | Activate a module for one tenant |
| `module:deactivate <slug> --tenant=<id> \| --all-tenants` | Deactivate for one tenant or all |
| `module:upgrade <slug>` | Run pending module migrations and bump the version |
| `module:remove <slug> [--force]` | Remove a module (`--force` deactivates all tenants first) |
| `module:list [--tenant=<id>]` | List discovered/installed modules (+ activation with `--tenant`) |
| `module:status <slug>` | Detailed status for one module |

Notes:
- `--path` on `make-module`/`install` points at a `module.json` that hasn't been discovered yet.
- `module:install` is idempotent. `module:remove` never rolls back migrations.
- Without `--force`, `module:remove` aborts if any tenant still has the module active.

---

## Facade API

### `Module`

```php
use Modularity\Support\Facades\Module;

Module::active(string $slug): bool                 // active for the current tenant
Module::activeFor(string $slug, int $tenantId): bool
Module::installed(string $slug): bool              // globally installed
Module::discovered(string $slug): bool             // present on filesystem/Composer
Module::menu(): NavigationRegistry
Module::permissions(): PermissionRegistry
Module::config(string $slug, string $key, mixed $default = null): mixed
Module::registry(): ModuleRegistry
```

### `Tenant`

```php
use Modularity\Support\Facades\Tenant;

Tenant::set(int $tenantId): void
Tenant::id(): ?int
Tenant::isSet(): bool
Tenant::forget(): void
Tenant::assertSet(): int   // throws TenantNotResolvedException if null
```

---

## Dependency Injection API

Every core service is a container singleton. Inject the orchestrator or specific lifecycle classes instead of touching internals:

```php
use Modularity\Core\Module\ModuleManager;
use Modularity\Core\Lifecycle\ModuleInstaller;
use Modularity\Core\Lifecycle\ModuleActivator;
use Modularity\Core\Lifecycle\ModuleDeactivator;

class SaasAdminController extends Controller
{
    public function __construct(
        private readonly ModuleInstaller $installer,
        private readonly ModuleActivator $activator,
        private readonly ModuleDeactivator $deactivator,
    ) {}

    public function install(string $slug): void
    {
        $this->installer->install($slug);            // returns InstalledModule
    }

    public function activate(string $slug, int $tenantId): void
    {
        $this->activator->activate($slug, $tenantId); // returns TenantModule
    }
}
```

**Lifecycle signatures:**

```php
$installer->install(string $slug, ?string $path = null): InstalledModule
$activator->activate(string $slug, int $tenantId): TenantModule
$deactivator->deactivate(string $slug, int $tenantId): void
$deactivator->deactivateAll(string $slug): void
$upgrader->upgrade(string $slug): InstalledModule
$remover->remove(string $slug, bool $force = false): void
```

**ModuleRegistry:**

```php
$registry = app(\Modularity\Core\Module\ModuleRegistry::class);

$registry->getManifest(string $slug): ?ManifestDTO
$registry->getInstalledRecord(string $slug): ?InstalledModule
$registry->isInstalled(string $slug): bool
$registry->isDiscovered(string $slug): bool
$registry->activeFor(string $slug, int $tenantId): bool
$registry->invalidateInstalled(): void
$registry->invalidateTenant(int $tenantId): void
$registry->invalidateAllTenants(): void
```

**NavigationRegistry:**

```php
$nav = Module::menu(); // or app(\Modularity\Core\Navigation\NavigationRegistry::class)

$nav->add(array|MenuItem $item): void
$nav->forTenant(int $tenantId, ?object $user = null): Collection
$nav->forTenantGrouped(int $tenantId, ?object $user = null): Collection
$nav->all(): array
$nav->flush(): void
```

---

## Events

Listen anywhere in your application — register listeners in your `EventServiceProvider`:

```php
use Modularity\Events\ModuleInstalled;
use Modularity\Events\ModuleActivated;
use Modularity\Events\ModuleDeactivated;
use Modularity\Events\ModuleUpgraded;
use Modularity\Events\ModuleRemoved;

protected $listen = [
    ModuleInstalled::class   => [SendWelcomeNotification::class],
    ModuleActivated::class   => [ProvisionTenantResources::class],
    ModuleDeactivated::class => [RevokeAccess::class],
    ModuleUpgraded::class    => [NotifyAdmins::class],
    ModuleRemoved::class     => [CleanupArtifacts::class],
];
```

| Event | Properties |
|---|---|
| `ModuleInstalled` | `ManifestDTO $manifest` |
| `ModuleActivated` | `string $slug`, `int $tenantId` |
| `ModuleDeactivated` | `string $slug`, `int $tenantId` |
| `ModuleUpgraded` | `string $slug`, `string $oldVersion`, `string $newVersion` |
| `ModuleRemoved` | `string $slug` |

`ModuleActivated` and `ModuleDeactivated` implement `TenantAwareEvent`.

> Listeners declared in a module's own `$listen` array only fire while that module is active. For work that must run on *first* activation (provisioning, seeding), listen in the **host app's** `EventServiceProvider` instead.

---

## Exceptions

| Exception | Namespace | When thrown |
|---|---|---|
| `ModuleNotFoundException` | `Core\Module\Exceptions` | Slug not found in the registry |
| `ModuleNotInstalledException` | `Core\Module\Exceptions` | Activation attempted before install |
| `ModuleAlreadyInstalledException` | `Core\Module\Exceptions` | Install attempted on an installed module |
| `ModuleStillActiveException` | `Core\Module\Exceptions` | Remove attempted while tenants still active |
| `DependencyNotInstalledException` | `Core\Module\Exceptions` | A declared dependency is missing at install |
| `IncompatibleModuleException` | `Core\Module\Exceptions` | `compatibility` constraint not satisfied |
| `InvalidManifestException` | `Core\Module\Exceptions` | `module.json` malformed or missing required fields |
| `CircularDependencyException` | `Core\Module\Exceptions` | Cycle detected in the dependency graph |
| `TenantNotResolvedException` | `Core\Tenancy\Exceptions` | `Tenant::assertSet()` called with no tenant |

---

## Database Schema

Four tables, all prefixed with `modularity_` (configurable via `migrations.table_prefix`).

### `modularity_installed_modules`

| Column | Type | Description |
|---|---|---|
| `id` | `bigint` PK | |
| `slug` | `varchar` UNIQUE | Module slug |
| `name` | `varchar` | Display name |
| `version` | `varchar` | SemVer |
| `checksum` | `varchar` nullable | Hash of `module.json` |
| `status` | `enum` | `installed` or `errored` |
| `installed_at` | `timestamp` | |
| `created_at` / `updated_at` | `timestamp` | |

### `modularity_tenant_modules`

| Column | Type | Description |
|---|---|---|
| `id` | `bigint` PK | |
| `tenant_id` | `bigint` | Application-defined tenant FK |
| `module_slug` | `varchar` | Module slug |
| `active` | `boolean` | Whether the module is currently active |
| `settings` | `json` nullable | Per-tenant module configuration |
| `activated_at` / `deactivated_at` | `timestamp` nullable | |

Unique constraint: `(tenant_id, module_slug)`.

### `modularity_tenant_module_subscriptions`

| Column | Type | Description |
|---|---|---|
| `id` | `bigint` PK | |
| `tenant_id` | `bigint` | |
| `module_slug` | `varchar` | |
| `status` | `enum` | `active`, `trial`, `free`, `cancelled` |
| `billing_cycle` | `enum` nullable | `monthly`, `yearly` |
| `starts_at` / `expires_at` | `timestamp` nullable | |

Phase 2 — currently unpopulated.

### `modularity_migration_log`

| Column | Type | Description |
|---|---|---|
| `id` | `bigint` PK | |
| `module_slug` | `varchar` | Owning module |
| `migration_file` | `varchar` | Migration filename (no path) |
| `batch` | `int` | Batch number |
| `ran_at` | `timestamp` | |

---

## Testing

Extend `Modularity\Tests\TestCase` for a pre-configured environment:

```php
use Modularity\Tests\TestCase;
use Modularity\Support\Facades\Tenant;

class LibraryTest extends TestCase
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

    public function test_books_are_scoped_to_tenant(): void
    {
        Book::create(['title' => 'Tenant 1 Book', 'author' => 'Author A']);

        Tenant::set(2);
        $this->assertCount(0, Book::all()); // invisible to tenant 2
    }
}
```

The base `TestCase`:
- Uses an in-memory SQLite database
- Disables the registry cache (`MODULARITY_CACHE=false`)
- Sets the permission driver to `null` (all checks pass)
- Registers the `Module` and `Tenant` facades
- Runs Modularity's infrastructure migrations automatically

**Run the suite:**

```bash
./vendor/bin/pest
./vendor/bin/pest --filter "LibraryTest"
```

### Testing modules

`module:make-module` generates a starter test at `Modules/<Name>/tests/<Name>Test.php` that already demonstrates the patterns below, so you usually don't write this from scratch. Add a testsuite for module tests once, in `phpunit.xml`:

```xml
<testsuite name="Modules">
    <directory>Modules/*/tests</directory>
</testsuite>
```

Use the shipped **`InteractsWithModules`** trait so you never touch the loader or registry directly:

```php
use Modularity\Testing\InteractsWithModules;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class, InteractsWithModules::class);

beforeEach(function () {
    // Installs (runs migrations + registers permissions), re-boots the loader so the
    // module's routes/views/nav register, and activates it for tenant 1 — one call.
    $this->installAndActivateModule('library', tenantId: 1);
});

it('scopes records to the current tenant', function () {
    $this->asTenant(1, fn () => Book::create(['title' => 'A']));
    $this->asTenant(2, fn () => expect(Book::count())->toBe(0)); // isolated
});
```

Trait API: `installModule()`, `activateModule()`, `installAndActivateModule()`, `bootModules()`, `asTenant()`.

**Why the re-boot?** The loader boots installed modules **once, at app boot**. With `RefreshDatabase` the database starts empty, so a module installed *mid-test* wouldn't have its provider registered for the request under test — `installModule()`/`bootModules()` re-run the loader to fix that. In production this never bites: `module:install` is a separate, persisted step, so every later request boots the module normally. (Alternatively, register the module's provider in `bootstrap/providers.php` so its routes always exist; `module.active` still gates per tenant.)

The package's own `tests/Http/` suite uses this trait with an on-disk fixture module as a reference.

---

## Publishing Assets

| Tag | Contents | Destination |
|---|---|---|
| `modularity-config` | `config/modularity.php` | `config/` |
| `modularity-migrations` | Four infrastructure migrations | `database/migrations/` |
| `modularity-stubs` | Scaffolding stubs for `module:make-module` | `stubs/modularity/` |

```bash
php artisan vendor:publish --tag=modularity-config
php artisan vendor:publish --tag=modularity-migrations
php artisan vendor:publish --tag=modularity-stubs
```

Publish the stubs to customize the output of `module:make-module` — the command reads from `stubs/modularity/` when present.

---

## Marketplace (Phase 2)

The marketplace is designed but ships Null Object implementations in Phase 1. Three contracts are defined:

```php
Modularity\Marketplace\Contracts\MarketplaceClientInterface
    fetchAvailable(): array
    fetchModule(string $slug): ?array

Modularity\Marketplace\Contracts\LicenseVerifierInterface
    verify(string $slug, int $tenantId): bool

Modularity\Marketplace\Contracts\SubscriptionManagerInterface
    isSubscribed(string $slug, int $tenantId): bool
    getSubscription(string $slug, int $tenantId): ?TenantModuleSubscription
```

Wire in a real implementation from any service provider:

```php
$this->app->bind(
    \Modularity\Marketplace\Contracts\MarketplaceClientInterface::class,
    \App\Marketplace\MyMarketplaceClient::class,
);
```

---

## Troubleshooting

**Module routes are not loading / every module URL 404s.**
Module routes register on every HTTP request once the module is **installed** — but **not on the CLI** (deliberate, to avoid booting every module on every artisan command), so `route:list` won't show them. Check:
- The module is installed: `php artisan module:list`.
- You're hitting it over HTTP, not asserting via `route:list`.
- If a specific tenant gets a 404, that's the `module.active` middleware — confirm activation (`php artisan module:list --tenant=<id>`) and that a tenant is set on the request (`Tenant::id()` is not null).
- **In tests**, modules installed *mid-test* won't have booted (the loader runs at app-boot, before the install). Use the `InteractsWithModules` trait's `installModule()` / `bootModules()`, or register the providers explicitly — see [Testing](#testing).

**Tenant is not being resolved.**
- If you rely on `ResolveTenantMiddleware`, ensure it runs on the route and that `tenancy.resolvers` lists the strategy you expect (default is `session` only).
- For `subdomain`/`domain`/`header`, set `MODULARITY_TENANT_MODEL` — these resolvers validate the value against that model and return `null` otherwise.
- Simplest fix in most apps: call `Tenant::set($user->tenant_id)` in your own auth flow.

**Cache is stale after install/activate.**
Set `MODULARITY_CACHE=false` during development, or flush manually:
```php
app(\Modularity\Core\Module\ModuleRegistry::class)->invalidateInstalled();
```

**`InvalidManifestException` on install.**
`module.json` is missing a required field, or the `slug` doesn't match `/^[a-z0-9]+(?:-[a-z0-9]+)*$/`.

**`DependencyNotInstalledException`.**
Install dependencies first:
```bash
php artisan module:install <dependency-slug>
php artisan module:install <dependent-slug>
```

**`Module::config()` always returns the default.**
It returns the default when no tenant is set. Guard with `Tenant::isSet()` in CLI or unauthenticated contexts.

**Migrations don't run for a module.**
Migrations must live in `database/migrations/` relative to the module root (where `module.json` is). The path is derived from `ManifestDTO::$path`.

---

## License

MIT — see [LICENSE](LICENSE).
</content>
</invoke>
