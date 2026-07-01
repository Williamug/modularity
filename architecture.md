# Modularity — Architecture

> How the package turns a plain Laravel app into a modular, multi-tenant, marketplace-ready platform — and how every piece connects to make that happen.

---

## 1. The core idea

Modularity is a **package that sits on top of your existing Laravel application**. It does not replace your app, own your users, or resolve your domains. It adds one capability:

> A **module** is an independently shippable bundle of features (migrations, routes, views, providers, permissions, navigation) that can be **discovered**, **installed** onto the platform, and **activated per tenant** — booting only when, and for whom, it should.

Everything in the codebase exists to answer three questions, in order:

1. **What modules exist?** → *Discovery*
2. **Which of them are installed on this platform?** → *Lifecycle / Registry*
3. **Which installed modules are active for the current tenant — and should boot right now?** → *Tenancy + Boot*

```
 discover ─────────► install ─────────► activate (per tenant) ─────────► boot
 (find module.json)  (run migrations,   (tenant opts in)                (register the
                      register perms,                                    module's providers)
                      record in DB)
```

---

## 2. Directory map (the mental model)

```
src/
├── ModularityServiceProvider.php      ← wires everything into Laravel's container
│
├── Core/
│   ├── Module/                        ← "what exists / what's installed"
│   │   ├── ModuleManifest.php         · parses & validates module.json → ManifestDTO
│   │   ├── ManifestDTO.php            · immutable description of one module
│   │   ├── ModuleLoader.php           · discovers manifests, then boots active ones
│   │   ├── ModuleRegistry.php         · the cached source of truth (installed + active)
│   │   ├── DependencyGraph.php        · topological sort (boot deps before dependents)
│   │   ├── MigrationRunner.php        · runs a module's migrations, logs them
│   │   └── ModuleManager.php          · public read API used by app & modules
│   │
│   ├── Lifecycle/                     ← "state transitions"
│   │   ├── ModuleInstaller.php        · install
│   │   ├── ModuleActivator.php        · activate for a tenant
│   │   ├── ModuleDeactivator.php      · deactivate (single / all / per-tenant)
│   │   ├── ModuleUpgrader.php         · run new migrations + bump version
│   │   └── ModuleRemover.php          · uninstall from the platform
│   │
│   ├── Tenancy/                       ← "who is this request for"
│   │   ├── TenantContext.php          · holds the current tenant id (per request)
│   │   ├── TenantResolver.php         · tries resolvers in order
│   │   ├── Resolvers/                 · session (default), + opt-in subdomain/domain/header
│   │   ├── TenantScope.php            · global Eloquent scope: WHERE tenant_id = ?
│   │   └── (BelongsToTenant trait in Support/Traits)
│   │
│   ├── Permissions/                   ← "can this user do X" (pluggable)
│   │   ├── Contracts/PermissionDriverInterface.php
│   │   ├── Drivers/ (Gate | Spatie | Null)
│   │   ├── PermissionRegistry.php     · tenant-scoped permission catalogue
│   │   └── ModulePermission.php
│   │
│   └── Navigation/                    ← "menu items contributed by modules"
│       └── NavigationRegistry.php
│
├── Models/                            ← the platform's own tables (modularity_*)
├── Events/ + Listeners/              ← cache invalidation is event-driven
├── Marketplace/                       ← Phase 2 hooks (null objects today)
├── Http/Middleware/                   ← ResolveTenantMiddleware
├── Console/Commands/                  ← module:install, :activate, :list, …
└── Support/                           ← Facades (Module, Tenant), Abstracts, Traits
```

---

## 3. What a module *is*: the manifest

Every module ships a `module.json` at its root:

```json
{
  "name": "Library",
  "slug": "library",
  "version": "1.2.0",
  "description": "Book lending for tenants",
  "providers": ["Modules\\Library\\Providers\\LibraryServiceProvider"],
  "permissions": ["library.view", "library.lend"],
  "dependencies": [{ "slug": "catalog" }],
  "compatibility": "^1.0"
}
```

- **`ModuleManifest::parse()`** reads it, enforces required fields (`name`, `slug`, `version`, `providers`), validates the slug shape (`^[a-z0-9]+(?:-[a-z0-9]+)*$`), and returns an immutable **`ManifestDTO`**.
- The DTO carries the absolute `path` so later steps know where the module's migrations/routes/views live.
- `compatibility` is a semver constraint checked against `config('modularity.version')` at install time (see §6).

The manifest is the **contract** between a module author and the platform. Nothing about a module is "magic" — it's all declared here.

---

## 4. Discovery — "what exists"

`ModuleLoader::discover()` runs during the service provider's `boot()` and fills the **registry** with `ManifestDTO`s from two sources:

1. **Local modules** — every subdirectory of `config('modularity.modules_path')` (default `base_path('Modules')`) that contains a `module.json`.
2. **Composer modules** — packages in `vendor/composer/installed.json` flagged with `extra.modularity.module = true`. Package names are validated against a `vendor/package` regex **before** being used to build a path, which blocks path-traversal via a crafted `installed.json`.

Discovery only **registers descriptions**. It does not touch the database, run code, or boot anything. It is safe to run on every request and every CLI command.

---

## 5. The Registry — the cached source of truth

`ModuleRegistry` is a **singleton** that answers the hot-path questions cheaply:

| Question | Method | Backed by |
|---|---|---|
| Is this module installed? | `isInstalled($slug)` | `modularity_installed_modules` table |
| Get the install record | `getInstalledRecord($slug)` | same |
| Is it active for tenant N? | `activeFor($slug, $tenantId)` | `modularity_tenant_modules` table |
| Which slugs are active for tenant N? | `activeSlugsForTenant($tenantId)` | same |

Two layers of caching keep these fast:

1. **In-memory** (per request) — `$installed`, `$tenantActive[]` arrays.
2. **Distributed cache** — keys `modularity.registry.installed` and `modularity.registry.tenant.{id}`, on the **configured** store (`config('modularity.cache.store')`), TTL `3600s`. Installed records are cached as **plain attribute arrays** (never live Eloquent models) and rehydrated on read, so the registry survives any serializing store (database/file/redis) instead of coming back as `__PHP_Incomplete_Class`.

Because there is no portable "delete by prefix" across cache drivers, the registry **tracks the set of tenant ids** it has cached under `modularity.registry.tenant_ids`, so `invalidateAllTenants()` can clear them all explicitly.

If the database is unavailable (e.g. before migrations run), `getInstalled()` catches the error and returns `[]` — so the package degrades gracefully instead of crashing artisan.

---

## 6. Lifecycle — state transitions

Each transition is a small, single-purpose class in `Core/Lifecycle/`. They are the **only** things that change platform state.

### Install (`ModuleInstaller`)
1. Resolve the manifest (idempotent — returns early if already installed).
2. Validate **dependencies** are installed (`DependencyNotInstalledException` otherwise).
3. Validate **compatibility** with `Composer\Semver\Semver::satisfies()` (now a hard dependency, so this never silently fails open).
4. Run the module's migrations **outside** a DB transaction (DDL isn't transactional on most engines) — a failure here aborts before any record is written, leaving a clean slate.
5. In a transaction: register permissions + create the `InstalledModule` record (with a **sha256** checksum of `module.json`).
6. Dispatch `ModuleInstalled`.

### Activate (`ModuleActivator`)
- Requires the module be installed.
- Calls `SubscriptionManagerInterface::check($tenantId, $slug)` and **throws `SubscriptionRequiredException`** if it returns false (today the null implementation returns true; Phase 2 swaps in a real one).
- Upserts a `TenantModule` row (`active = true`) and dispatches `ModuleActivated`.

### Deactivate (`ModuleDeactivator`)
- `deactivate` (one tenant), `deactivateAll` (every tenant of a module — single bulk UPDATE), `deactivateAllForTenant` (wire this to your Tenant's `deleting` event). Each dispatches `ModuleDeactivated`.

### Upgrade (`ModuleUpgrader`)
- Runs any new migrations, bumps the stored version, dispatches `ModuleUpgraded`.

### Remove (`ModuleRemover`)
- Refuses if tenants still have it active (unless `--force`, which deactivates them first).
- Deletes the `InstalledModule` record and its migration log, dispatches `ModuleRemoved`. (Note: it does **not** roll back the module's own schema — that's a deliberate, documented choice.)

> **Cache invalidation is event-driven.** Lifecycle classes do **not** poke the registry directly. They dispatch domain events; `CacheInvalidationListener` (wired in the provider) reacts and calls the right `invalidate…()` method. One mechanism, one place — no double invalidation.

---

## 7. Tenancy — "who is this request for"

This is the part that **stays out of your app's way**. The package never decides what a tenant is or how you identify one by URL — that's your application's job.

- **`TenantContext`** is a per-request singleton holding a single `?int` tenant id. Your app sets it once you know the tenant:

  ```php
  Tenant::set($user->tenant_id);   // Support/Facades/Tenant → TenantContext
  ```

- **`ResolveTenantMiddleware`** (alias `resolve.tenant`) optionally runs a chain of **resolvers** and sets the context, then **forgets** it after the response (no bleed across long-running workers).

- **Resolvers** are opt-in conveniences. The default chain is **`['session']`** only — host-controlled and safe. `subdomain`, `domain`, and `header` exist but are **off by default**, because "a request presented value X" is not the same as "this request is *authorized* as tenant X." Enable them only with that understanding, or just call `Tenant::set()` yourself.

- **`TenantScope` + `BelongsToTenant` trait** give module models automatic isolation: a global scope adds `WHERE tenant_id = <current>` to every query, and `creating` stamps the tenant id on new rows. Add the trait to any model that should be tenant-scoped.

The registry then uses the resolved tenant id to decide which modules are active — which drives boot.

---

## 8. Boot — registering only what should run

After discovery, `ModuleLoader::boot()`:

1. Filters discovered modules to those that are **installed** (DB-unavailable → empty, safe).
2. **Topologically sorts** them with `DependencyGraph` (Kahn's algorithm) so dependencies boot before dependents; a cycle throws `CircularDependencyException` and aborts boot with a logged error rather than half-booting.
3. For each module, in order, `bootModule()`:
   - skips modules whose install record is `errored`;
   - registers the providers of **every installed (non-errored) module**, *independent of the current tenant*. Routes, views and navigation are global registrations and the tenant isn't known yet at boot (session/auth tenancy resolves later, in middleware). Per-tenant access is enforced at request time by the `module.active` middleware, and `NavigationRegistry::forTenant()` filters the menu per tenant when it renders;
   - also registers the module's declared permissions (so their Gate abilities exist on every request — a host grants them to users via `Gate::before` or its own permission system);
   - **never auto-boots on a real artisan command** — booting every installed module on every CLI invocation (`artisan list`, `key:generate`, `migrate`, …) was the original memory/freeze problem, so module CLI commands must be registered deliberately; the test environment is exempt so the HTTP path stays exercisable;
   - registers each provider class listed in the manifest (guarding against missing classes and double registration).

A module's own **`ModuleServiceProvider`** (in `Support/Abstracts/`) is the base class authors extend. On boot it guards a missing `$slug`, then loads the module's routes, views, Livewire components, navigation, and event listeners — unconditionally, because the tenant isn't known yet. Access is gated per request by the `module.active` middleware. See `INTEGRATION.md` for the host wiring.

---

## 9. Permissions — pluggable, never mandatory

The package only needs something that can (a) **register** a module's permission names and (b) answer **`userCan()`**. That contract is `PermissionDriverInterface`.

- Built-in drivers: **`gate`** (default, Laravel's Gate), **`spatie`** (optional integration — the package never *requires* spatie/laravel-permission), **`null`** (testing).
- **Bring your own:** set `config('modularity.permissions.driver')` to the **FQCN** of any class implementing the interface. The provider resolves it through the container, so it can declare its own dependencies. Spatie is just one optional choice among many — your own system is a first-class path.
- **`PermissionRegistry::allForTenant()`** returns only the permissions belonging to modules **active for that tenant**, preventing cross-tenant permission leakage.

---

## 10. Navigation

Modules contribute menu items to `NavigationRegistry`. `forTenant($tenantId, $user)` returns only the items whose module is active for that tenant **and** whose optional permission passes for the user, sorted by `order`. This lets the host render a single menu that automatically reflects each tenant's activated modules.

---

## 11. Events & cache invalidation

```
ModuleInstaller   ─► ModuleInstalled   ┐
ModuleActivator   ─► ModuleActivated   │
ModuleDeactivator ─► ModuleDeactivated ├─►  CacheInvalidationListener ─► ModuleRegistry::invalidate…()
ModuleUpgrader    ─► ModuleUpgraded    │
ModuleRemover     ─► ModuleRemoved     ┘
```

Events are dispatched synchronously, so by the time a lifecycle call returns, both the in-memory and distributed caches are consistent. The same events are also your **extension points** — the host app can listen to them to send notifications, write audit logs, trigger billing, etc.

---

## 12. Marketplace (Phase 2)

`Marketplace/` ships **null-object** implementations (`NullSubscriptionManager`, `NullMarketplaceClient`, `NullLicenseVerifier`) so Phase 1 runs with zero remote calls. The interfaces are already wired into activation (`SubscriptionManagerInterface::check`), so swapping in real implementations later enforces subscriptions/licensing **without touching the lifecycle code**.

---

## 13. The data model

| Table | Purpose |
|---|---|
| `modularity_installed_modules` | one row per module installed on the platform (slug, version, checksum, status) |
| `modularity_tenant_modules` | which modules are active for which tenant (+ per-tenant `settings` JSON); `unique(tenant_id, module_slug)` |
| `modularity_tenant_module_subscriptions` | Phase 2 subscription/billing state; `unique(tenant_id, module_slug)` |
| `modularity_migration_log` | which migration files have run for each module; `unique(module_slug, migration_file)` |

These are the **platform's** tables. A module's own business tables are created by the module's own migrations and named by the module author.

---

## 14. End-to-end: a request

```
HTTP request
   │
   ├─ (your app, or ResolveTenantMiddleware) sets TenantContext  →  tenant = 42
   │
   ├─ ModularityServiceProvider::boot already ran for this process:
   │     discover()  → registry full of ManifestDTOs
   │     boot()      → boots providers of every installed module (dependency-ordered,
   │                   skipping errored ones), independent of tenant
   │
   ├─ those module providers registered routes/views/menu/permissions globally;
   │   the `module.active:<slug>` middleware 404s tenant 42 on any module it has
   │   not activated, and the menu is filtered per tenant when rendered
   │
   └─ controller runs; TenantScope keeps every module query scoped to tenant 42;
      Module::active('library'), Module::config(...), Navigation, and permission
      checks all read from the cached registry.
```

---

## 15. Design principles (why it's shaped this way)

- **Sits on top, doesn't take over.** Tenancy identification, auth, and domains stay in your app. The package only consumes a tenant id.
- **Declare, don't discover-by-magic.** Everything a module does is in its `module.json` and its provider.
- **One writer per state change.** Only `Core/Lifecycle/*` mutates platform state; everyone else reads through the registry.
- **Cache aggressively, invalidate via events.** Hot paths never hit the DB twice; consistency is restored by a single listener.
- **Fail safe.** DB down → empty registry. Cycle → abort boot. Missing provider → log and skip. Never half-boot, never crash artisan.
- **Pluggable at the edges.** Permission driver, tenant resolvers, marketplace, and event listeners are all swappable without editing the core.
```
