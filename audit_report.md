# 🔍 Code Analysis Report — Modularity (Fresh)

**Date:** 2026-06-18

**Audit Type:** Manual static analysis of current `dev-2` source

**Scope:** All PHP under `src/`, database migrations, stubs, and `config/modularity.php`. Covers security, correctness, performance, architecture, data integrity, and observability.

> **Note:** This replaces the previous report, which was generated against an older revision. Almost every issue in that report (all 3 Critical, most High) has since been remediated. The findings below reflect the code as it actually stands today.

---

## 📊 Executive Summary

- Total Issues Found: 9
- Critical: 0
- High: 1
- Medium: 2
- Low: 6

**Code Health Score:** 82 / 100

**What's already solid (previously-flagged, now fixed):**
- `HeaderTenantResolver` verifies tenant existence in the DB and rejects `<= 0`.
- `ModuleRegistry` invalidation uses the *configured* cache store (`cacheStore()`), and `invalidateAllTenants()` tracks tenant IDs so it can clear distributed keys.
- `ModuleActivator` enforces `subscriptions->check()` and throws `SubscriptionRequiredException`.
- `ModuleInstaller` runs migrations before a `DB::transaction`, uses **sha256** (not md5) checksums, and validates compatibility.
- `MigrationRunner` uses the `Migrator` directly, throws on failure, and only logs migrations that actually ran.
- `ModuleServiceProvider::boot()` guards a missing `$slug` and degrades gracefully.
- `ModuleLoader` only boots **installed + active** modules, never auto-boots in CLI, and validates composer package names against path traversal.
- `ListModulesCommand` batch-loads active counts in one grouped query (N+1 fixed).
- Subscriptions table now has a `unique(tenant_id, module_slug)` constraint (migration `000005`).
- Lifecycle commands log failures to `Log::error` with the exception.

---

## 🟠 High Issues

---

- **Severity:** High
- **Category:** Security
- **Location:** `config/modularity.php:56` (default `resolvers` chain) + `src/Core/Tenancy/Resolvers/HeaderTenantResolver.php`
- **Description:** `header` is enabled by default in the resolver chain (`['subdomain', 'domain', 'header', 'session']`). `HeaderTenantResolver` now verifies that the `X-Tenant-ID` value corresponds to a tenant that *exists*, but it performs **no authorization check** that the current request/user is entitled to act as that tenant. Existence ≠ authorization.
- **Risk:** Any client that can reach a route behind `ResolveTenantMiddleware` can set `X-Tenant-ID` to any *valid* tenant ID (often small, guessable integers) and assume that tenant's context — activated modules, navigation, and `TenantScope`-filtered data. The existence check only blocks non-existent IDs; it does not stop cross-tenant access between real tenants. Shipping `header` in the default chain makes this the out-of-the-box behavior.
- **Recommendation:** Remove `header` from the default resolver list (opt-in only), or document loudly that it must be paired with an app-level guard that confirms the authenticated principal belongs to the requested tenant.

---

## 🟡 Medium Issues

---

- **Severity:** Medium
- **Category:** Correctness / Security
- **Location:** `src/Core/Lifecycle/ModuleInstaller.php:97–111` (`validateCompatibility`)
- **Description:** Compatibility enforcement is conditional on `\Composer\Semver\Semver` being available. `composer/semver` is **not** listed in `composer.json`'s `require` block, so in a production install where it is absent, the method only emits a `Log::warning` and then lets installation proceed.
- **Risk:** On hosts without `composer/semver`, a module declaring an incompatible constraint (e.g. `"^0.5"` on a `1.0.0` platform) installs and boots with no rejection — exactly the silent-failure case the check is meant to prevent. The guarantee is environment-dependent rather than enforced.
- **Recommendation:** Add `composer/semver` to `require`, or fail closed (reject install) when a non-trivial constraint is declared but cannot be verified.

---

- **Severity:** Medium
- **Category:** Security
- **Location:** `src/Core/Permissions/Drivers/SpatiePermissionDriver.php:23–28` and `GatePermissionDriver.php:28–31`
- **Description:** Both drivers' `allForTenant(int $tenantId)` ignore the `$tenantId` argument. Spatie returns `Permission::pluck('name')->all()` (every permission in the table); Gate returns `array_keys($this->registered)` (every permission registered in the process). The method name implies tenant scoping.
- **Risk:** The correct, tenant-scoped path is `PermissionRegistry::allForTenant()` (which filters by the tenant's active modules), so there is no active leak today. But the driver method is public and misleadingly named — any caller that reaches for `driver->allForTenant()` directly will silently get a cross-tenant superset, including permissions from deactivated/removed modules in the Spatie case.
- **Recommendation:** Either rename these to `all()` to reflect that they are global, or remove them from the public surface and route everything through `PermissionRegistry`.

---

## 🔵 Low Issues

---

- **Severity:** Low
- **Category:** Architecture / Performance
- **Location:** `ModuleRemover.php:48`, `ModuleUpgrader.php:48–50`, `ModuleActivator.php:33`, `ModuleDeactivator.php` vs `src/Listeners/CacheInvalidationListener.php`
- **Description:** Lifecycle classes invalidate the registry directly **and** dispatch an event whose `CacheInvalidationListener` performs the same invalidation. e.g. `ModuleRemover` calls `invalidateInstalled()` then dispatches `ModuleRemoved`, whose handler calls `invalidateInstalled()` + `invalidateAllTenants()` again. (`ModuleInstaller` correctly relies on the listener only — the others are inconsistent.)
- **Risk:** Redundant work, not incorrect. `invalidateAllTenants()` re-iterates and re-forgets every tracked tenant key twice per remove/upgrade. Also signals unclear ownership between the lifecycle class and the listener.
- **Recommendation:** Pick one mechanism — preferably the event listener — and drop the direct calls.

---

- **Severity:** Low
- **Category:** Data Integrity
- **Location:** `src/Models/InstalledModule.php:13` (`installed_at` in `$fillable`, `$timestamps = false`)
- **Description:** `installed_at` is mass-assignable while Eloquent timestamp management is off and the DB default is `useCurrent()`. Callers can pass an arbitrary `installed_at` via `create([...])`.
- **Risk:** `InstalledModule::create(['installed_at' => '2000-01-01'])` corrupts audit/diagnostic output in `module:status`. (`ModuleInstaller::install()` does not set it today, so this is latent.)
- **Recommendation:** Remove `installed_at` from `$fillable`.

---

- **Severity:** Low
- **Category:** Data Integrity
- **Location:** `database/migrations/2024_01_01_000002...` and `_000003...`
- **Description:** `tenant_id` and `module_slug` columns carry no foreign-key constraints (by design, since the package does not own the host's tenant table or the installed-modules lifecycle).
- **Risk:** Deleting a tenant or removing a module out-of-band (`ModuleRemover` deletes the `InstalledModule` and migration log but leaves `TenantModule`/`TenantModuleSubscription` rows) leaves orphans that are invisible without reconciliation.
- **Recommendation:** Document the cleanup contract (wire `deactivateAllForTenant()` to the tenant's `deleting` event) and consider optional FKs where the host schema allows.

---

- **Severity:** Low
- **Category:** Architecture / Testability
- **Location:** `src/Core/Tenancy/TenantScope.php:13,24` and `src/Core/Navigation/NavigationRegistry.php:34`
- **Description:** `app(TenantContext::class)` / `app(ModuleManager::class)` are resolved via the service locator inside `apply()`, the static `creating()`, and a collection closure, rather than via injection.
- **Risk:** These classes can't be unit-tested without a booted container; the static `TenantScope::creating()` can never receive injection. Low impact, but it permanently couples to the `app()` helper.
- **Recommendation:** Acceptable for a global scope; note it as a known constraint.

---

- **Severity:** Low
- **Category:** Correctness / Observability
- **Location:** `HeaderTenantResolver.php:25`, `SubdomainTenantResolver.php:31`, `DomainTenantResolver.php:24`
- **Description:** Positive tenant lookups are cached for 300s with no invalidation hook. A tenant that is deleted, renamed, or has its domain changed continues to resolve (or fails to resolve) for up to 5 minutes.
- **Risk:** Brief window where a removed tenant's context still resolves, or a re-pointed domain lags. Minor for most apps.
- **Recommendation:** Expose a documented cache-key convention so hosts can bust these on tenant mutation.

---

- **Severity:** Low
- **Category:** Observability
- **Location:** `src/Core/Module/Exceptions/CircularDependencyException.php` (via `DependencyGraph::resolve()`)
- **Description:** On a cycle, the exception reports the set of nodes with non-zero in-degree (the participants), not the actual edge path (`A → B → C → A`).
- **Risk:** Operators must manually inspect manifests to find the offending chain. Low, but slows incident resolution with many modules.
- **Recommendation:** Reconstruct and include the concrete cycle path in the message.

---

## Summary

The package is in good shape. There are **no critical or correctness-breaking bugs** in the current code. The single High item is a defaults/hardening concern around header-based tenant resolution (existence is checked, authorization is not). The two Medium items are a conditional compatibility check and misleadingly-named permission-driver methods. The Low items are polish: redundant cache invalidation, mass-assignable `installed_at`, missing FKs, service-location coupling, lookup-cache staleness, and a less-actionable circular-dependency message.
