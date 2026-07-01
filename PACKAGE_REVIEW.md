> **Resolution status (all items addressed).** BUG-1: registry now caches plain
> attribute arrays and rehydrates (`ModuleRegistry`). BUG-2: `TenantResolver::class`
> aliased to `modularity.resolver`. BUG-3: installed modules' providers/routes/views/nav
> boot for every installed module independent of tenant; access gated per request by the
> new shipped `module.active` middleware (`EnsureModuleActive`); real CLI still skipped.
> BUG-4: permissions registered on boot by the loader; host wiring documented. BUG-5:
> stubs use `module.active`, a clarified layout component, and cleaner table naming.
> Added an HTTP feature suite (`tests/Http`) covering all four, plus opt-in fail-closed
> tenant scoping (`MODULARITY_TENANCY_STRICT`) and a host-integration guide
> (`INTEGRATION.md`). Suite: **58 passing**.

# Modularity — Package Review

> Findings from building a real, working demo (`../module-demo`): two related modules
> (**Customer** + **Invoice**, Invoice depends on Customer) with views, permissions,
> navigation, and single-database multi-tenancy on the Laravel 13 / Livewire-Flux
> starter kit. Every claim below was verified against the source and/or reproduced at
> runtime. File references are `src/...:line`.

---

## 1. Executive summary

The package is **architecturally strong** — clean separation of concerns, a single-writer
lifecycle, event-driven cache invalidation, dependency-ordered booting, security-conscious
discovery, and 50 passing unit/lifecycle tests. The *ideas* are well thought out and the
docs (`architecture.md`) are excellent.

However, **the package cannot run a conventional session/auth multi-tenant web app out of
the box.** Three independent defects each break the primary HTTP path, and a fourth leaves
the permission story half-wired. None are visible to the existing test suite because it has
**no HTTP/middleware/integration coverage** — every test exercises lifecycle classes and
units directly. The demo only works because the host application papers over all four issues.

**Bottom line:** great Phase-1 core; the HTTP integration layer is unfinished and untested.

---

## 2. Bugs (verified, with reproduction)

### 🔴 BUG-1 — Registry caches Eloquent models → `__PHP_Incomplete_Class` on any serializing cache store
**Severity: Critical (crashes every request and every artisan command).**

`ModuleRegistry::getInstalled()` caches live `InstalledModule` Eloquent models:

```php
// src/Core/Module/ModuleRegistry.php:120,132
$records = InstalledModule::all()->keyBy('slug')->all();
...
$this->cacheStore()->put($cacheKey, $records, $cacheTtl);  // serializes models
```

With the default `CACHE_STORE=database` (and `file`, `redis`, `memcached` — anything that
serializes), the next process reads the value back as `__PHP_Incomplete_Class`, and
`getInstalledRecord(): ?InstalledModule` then throws a `TypeError` inside
`ModuleLoader::bootModule()` during `ModularityServiceProvider::boot()`. Because this runs on
**every** boot, the whole app (and `php artisan` itself) dies.

**Repro:** fresh app, `CACHE_STORE=database`, install a module, run any second command →
`TypeError: ...getInstalledRecord(): Return value must be of type ?...InstalledModule, __PHP_Incomplete_Class returned`.

**Only works today with** `array` cache (the package's own test suite uses `CACHE_STORE=array`,
which stores the live object and never serializes — masking the bug) or `MODULARITY_CACHE=false`.

**Fix:** cache plain data, not models. Store an array of primitives (or a small readonly DTO)
keyed by slug, and rehydrate/skip models. Same applies to anything else cached. This is the
single most important fix — it makes the documented `CACHE_STORE=database` default usable.

---

### 🔴 BUG-2 — `resolve.tenant` middleware is unresolvable
**Severity: Critical (any route using the package's own middleware 500s).**

```php
// src/Http/Middleware/ResolveTenantMiddleware.php
public function __construct(
    private readonly TenantResolver $resolver,   // concrete class
    private readonly TenantContext $context,
) {}
```

The provider binds the resolver only under the string key `modularity.resolver`
(`src/ModularityServiceProvider.php:79`) and **never aliases `TenantResolver::class` to it**
(compare the other services at lines 145–150 which *are* aliased). So the container tries to
autowire `TenantResolver`, fails on its `array $resolvers` constructor arg, and throws:

> `Unresolvable dependency resolving [Parameter #0 [ <required> array $resolvers ]] in class Modularity\Core\Tenancy\TenantResolver`

This is the middleware the package's own route stub tells authors to use (see BUG-5), so the
generated scaffolding is dead on arrival.

**Fix:** add `$this->app->alias('modularity.resolver', TenantResolver::class);` in
`registerCoreServices()`.

---

### 🔴 BUG-3 — Module providers never boot for the *documented default* tenancy
**Severity: Critical for session/auth tenancy (no routes, views, or nav register).**

```php
// src/Core/Module/ModuleLoader.php:71-83
$isActive = $tenantId !== null
    ? $this->registry->activeFor($manifest->slug, $tenantId)
    : false;
if (! $isActive) {
    return;   // provider never registered → routes/views/nav never load
}
```

A module's `ServiceProvider` (and therefore its routes/views/navigation) is only registered
when the module is active **for the current tenant at the moment providers boot**. But the
package's *recommended* tenancy is session/auth-based — `Tenant::set($user->tenant_id)` from
middleware (per `architecture.md §7` and the config comments) — which runs **after** providers
have booted. Result: on a normal web request the tenant is unknown at boot, `$isActive` is
`false`, and **module routes are never registered** (`route:list` shows nothing; every module
URL 404s).

This boot-time gating only works for the opt-in `subdomain`/`domain`/`header` resolvers, whose
value is available from the request host during boot — i.e. the exact resolvers the docs warn
against as "not authorization." The safe, recommended default is structurally incompatible with
how routes get registered.

**Fix:** separate the two concerns. Register a module's routes/views/navigation for every
*installed* module at boot (independent of tenant), and enforce *per-tenant access* at request
time — either ship a `module.active` route middleware or check activeness in a base controller.
Today the host must do this itself (see §3).

---

### 🟠 BUG-4 — Permissions are never registered at runtime
**Severity: Medium (permission catalogue is empty outside the install process).**

`PermissionRegistry::registerForModule()` is called in exactly one place —
`ModuleInstaller::install()` (`src/Core/Lifecycle/ModuleInstaller.php:52`). Nothing registers a
module's permissions during a normal boot. Consequently, on every HTTP request:

- `GatePermissionDriver` has defined **no** Gate abilities, and
- `PermissionRegistry::allForTenant()` returns `[]` (its `$modulePermissions` map is empty).

So `$user->can('customer.create')` resolves against undefined abilities, and the "tenant-scoped
permission catalogue" the docs advertise is empty at runtime. The package gives you the
machinery (`manifest.permissions`, a driver contract) but nothing wires it into a live request.
The demo had to add its own `Gate::before` mapping a per-user permission list.

**Fix:** have the module `ServiceProvider` (or the loader) call `registerForModule()` on boot
for installed modules, and document how a host grants those abilities to users.

---

### 🟡 BUG-5 — Generated scaffolding is non-functional
**Severity: Low–Medium (new modules don't work until hand-fixed).**

`stubs/module/routes/web.stub:6` uses `['web','auth','resolve.tenant']` → hits BUG-2.
`stubs/module/resources/views/index.blade.stub:1` uses `<x-layouts.app>` (single dot), but the
official Livewire/Flux starter kit registers the layout as `<x-layouts::app>` (namespaced) — so
the scaffolded view throws "Unable to locate a class or view for component [layouts.app]".
The default table name pattern `{{slug}}_{{snake}}s` also yields awkward names like
`customer_customers`.

**Fix:** make stubs target the broken-free path (no `resolve.tenant`, or fix BUG-2 first),
parameterize/clarify the layout component, and reconsider default table naming.

---

## 3. What a host app must add today (the demo's workarounds)

To get a working app I had to supply, in the host, everything the package's HTTP layer doesn't:

- `MODULARITY_CACHE=false` to dodge **BUG-1**.
- Drop `resolve.tenant` and set the tenant in a host middleware (`Tenant::set($user->tenant_id)`),
  ordered **before** `SubstituteBindings` (via `prependToPriorityList`) so route-model binding is
  tenant-scoped — works around **BUG-2** and makes binding isolation real.
- Register the two module providers in `bootstrap/providers.php` to work around **BUG-3**.
- A host `module.active:<slug>` middleware + `NavigationRegistry::forTenant()` filtering to gate
  per-tenant access (the package ships no such middleware).
- A `Gate::before` mapping a per-user permission list onto module abilities, to work around **BUG-4**.

None of this is in the docs; a first-time integrator would be stuck at the first 404/500.

---

## 4. Observations & design notes

- **Tenant-unset means UNSCOPED, not empty.** `TenantScope::apply()` (`src/Core/Tenancy/TenantScope.php`)
  returns early when no tenant is set, so any query made before the tenant is resolved returns
  **all tenants' rows**. Combined with BUG-3's boot ordering this is a real data-leak footgun —
  forgetting to set the tenant silently disables isolation rather than failing closed. Consider a
  strict mode that throws when a `BelongsToTenant` model is queried with no tenant context.
- **CLI never boots module providers** (`ModuleLoader::bootModule` comment + behavior). This is a
  deliberate, well-reasoned fix for the original memory blow-up, but it means module artisan
  commands need explicit registration and `route:list` can't show module routes — worth calling
  out in docs.
- **Event-driven cache invalidation is elegant** (`CacheInvalidationListener`), but its value is
  undercut by BUG-1: the thing being cached can't survive a real cache store.
- **Graceful DB-down handling** (`getInstalled()` try/catch → `[]`) is a nice touch.
- **Security-conscious discovery** — composer package-name regex blocks path traversal, slug regex,
  semver compatibility enforced as a hard dep. Good.

---

## 5. Strengths

1. **Clean architecture / SRP.** Discovery, registry, lifecycle, tenancy, permissions, navigation,
   marketplace are cleanly separated; only `Core/Lifecycle/*` mutates state.
2. **Solid unit/lifecycle test coverage** — 50 tests, 102 assertions, all green.
3. **Dependency resolution** — Kahn topological sort with circular-dependency detection.
4. **Pluggability** — permission driver (gate/spatie/null/FQCN), tenant resolvers, marketplace
   null-objects, all swappable without touching the core.
5. **Defensive coding** — DB-down degradation, path-traversal guard, double-registration guards,
   manifest validation.
6. **Excellent conceptual docs** (`architecture.md`) — the mental model is clear and accurate
   about *intent*.

---

## 6. Weaknesses

1. **No HTTP/middleware/integration tests.** This is the root cause of BUG-1..4 shipping: the
   suite never boots the framework through a request, so a fully-broken default path looks green.
2. **The documented default tenancy doesn't work end-to-end** (BUG-2 + BUG-3).
3. **Caching is incompatible with the default (and any serializing) cache store** (BUG-1).
4. **Permission story is half-implemented** at the request layer (BUG-4) and undocumented for hosts.
5. **Scaffolding produces non-working modules** (BUG-5).
6. **No host-integration guide.** `architecture.md` explains internals beautifully but there's no
   "here's a minimal working app" — the gap where all four bugs hide.
7. **Fail-open tenant scoping** is a security footgun (§4).

---

## 7. Recommended priorities

1. **BUG-1** — stop caching Eloquent models; cache primitives/DTOs. (Unblocks the default cache store.)
2. **BUG-2** — alias `TenantResolver::class`. (One line.)
3. **BUG-3** — register installed modules' routes/views/nav at boot; gate access per-request; ship a
   `module.active` middleware.
4. **BUG-4** — register permissions on boot and document host wiring.
5. **Add an HTTP feature-test harness** (a tiny in-package app with a route behind a module) so 1–4
   stay fixed. The demo's `tests/Feature/ModulesTest.php` is a working template.
6. **BUG-5** — fix stubs to scaffold a module that runs immediately.
7. Write a **"Integrating into your app"** guide; consider a strict fail-closed tenant scope.

---

## 8. Appendix — verification

- Demo: `../module-demo`, modules under `Modules/Customer` and `Modules/Invoice`.
- Reproduced BUG-1 (TypeError on 2nd command), BUG-2 (unresolvable dependency in tests),
  BUG-3 (`route:list` empty until providers host-registered).
- 6 host feature tests pass (isolation, route-binding scope, permission gating, nav filtering,
  module-active gate, cross-tenant FK rejection); full host suite 35/35; package suite 50/50.
- Live HTTP smoke test (database cache, `MODULARITY_CACHE=false`): two tenants, owner/viewer
  logins, confirmed data isolation and permission gating across both businesses.
