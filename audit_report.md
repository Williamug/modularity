# 🔍 Code Analysis Report — Modularity

**Date:** 2026-06-18

**Audit Type:** Automated Static Analysis

**Scope:** Full static analysis of the Modularity Laravel package — all PHP source files under `src/`, database migrations, stub files, and configuration. Covers security, correctness, performance, architecture, data integrity, and observability.

---

## 📊 Executive Summary

- Total Issues Found: 29
- Critical: 3
- High: 8
- Medium: 10
- Low: 8

**Code Health Score:** 44 / 100

---

## 🔴 Critical Issues

---

- **Severity:** Critical
- **Category:** Security
- **Location:** `src/Core/Tenancy/Resolvers/HeaderTenantResolver.php:12–18`
- **Description:** The `X-Tenant-ID` header value is accepted and returned as the resolved tenant ID after only a `ctype_digit` check. There is no database lookup to verify the tenant actually exists. Any HTTP client that can reach a route protected by `ResolveTenantMiddleware` can send an arbitrary `X-Tenant-ID` header and impersonate any tenant.
- **Risk:** Complete tenant impersonation. An unauthenticated or unauthorized user can set their effective tenant context to any integer, gaining access to that tenant's activated modules, routes, navigation items, and scoped data.

---

- **Severity:** Critical
- **Category:** Error
- **Location:** `src/Core/Module/ModuleRegistry.php:69, 75` vs `98, 118, 135, 152`
- **Description:** `invalidateInstalled()` (line 69) calls `Cache::forget('modularity.registry.installed')` which targets the **default** cache store. `invalidateTenant()` (line 75) also calls `Cache::forget(...)` on the default store. However, all read/write operations use `Cache::store(config('modularity.cache.store'))->get/put(...)` which targets the **configured** store. When `modularity.cache.store` differs from the default store (e.g., `redis` vs `file`), all invalidation calls silently operate on the wrong store and have no effect.
- **Risk:** Stale module state is served indefinitely after install, activation, deactivation, or removal operations. Tenants see modules as active after deactivation; removed modules continue to appear installed. The TTL is 3600 seconds, meaning the bug compounds for up to one hour per lifecycle event.

---

- **Severity:** Critical
- **Category:** Security
- **Location:** `src/Core/Lifecycle/ModuleActivator.php:27`
- **Description:** `$this->subscriptions->check($tenantId, $slug)` return value is discarded. The `SubscriptionManagerInterface` contract states the return value indicates whether the tenant may activate the module. Execution unconditionally proceeds to `updateOrCreate()` regardless of what `check()` returns.
- **Risk:** When a real `SubscriptionManagerInterface` implementation is wired in (Phase 2), subscription enforcement will be silently bypassed. Tenants with expired or invalid subscriptions can activate any module. This is a structural enforcement gap that will persist into production even after the null implementation is replaced.

---

## 🟠 High Issues

---

- **Severity:** High
- **Category:** Error
- **Location:** `src/Core/Module/MigrationRunner.php:22–35`
- **Description:** The return value of `Artisan::call('migrate', [...])` is not checked. If the migration command fails (returns a non-zero exit code or throws), the method proceeds to write entries into `modularity_migration_log` for all pending files (lines 30–35), marking them as successfully run when they were not.
- **Risk:** Failed migrations are permanently recorded as complete. `pendingForModule()` will never return those files again. The module's schema is partially applied and cannot be fixed by re-running the upgrade command without manual database intervention.

---

- **Severity:** High
- **Category:** Error
- **Location:** `src/Core/Lifecycle/ModuleUpgrader.php:34`
- **Description:** `$migrationsPath = $manifest?->path.'/database/migrations'` — when `$manifest` is `null`, PHP evaluates `null . '/database/migrations'` as the string `'/database/migrations'` (an absolute path from root). The `is_dir()` check on line 38 provides incidental protection only if that path does not exist on the filesystem.
- **Risk:** On Unix systems where `/database/migrations` happens to be a real directory (possible in containerized environments), `MigrationRunner::runForModule()` will be invoked against an unrelated path, potentially running arbitrary migration files outside the module.

---

- **Severity:** High
- **Category:** Error
- **Location:** `src/Core/Lifecycle/ModuleInstaller.php:25–57`
- **Description:** `ModuleInstaller::install()` runs migrations (line 39), registers permissions (line 41), then creates the `InstalledModule` record (line 45) — all without a database transaction. If `InstalledModule::create()` fails (e.g., a unique constraint race), the module's migrations have already been applied and permissions already registered with no rollback path.
- **Risk:** The module is left in a permanently broken state: schema changes exist in the database and permissions are registered, but no `InstalledModule` record exists. Subsequent install attempts will re-run migrations; the `UNIQUE` constraint on `(module_slug, migration_file)` in `modularity_migration_log` will then throw, making the module uninstallable without manual database repair.

---

- **Severity:** High
- **Category:** Security
- **Location:** `src/Support/Abstracts/ModuleServiceProvider.php:12`
- **Description:** `protected string $slug;` is declared as a typed property without a default value and without the `abstract` keyword. PHP does not enforce that subclasses initialize this property at definition time. Any call that reads `$this->slug` throws `Error: Typed property ModuleServiceProvider::$slug must not be accessed before initialization`.
- **Risk:** A single module with a misconfigured provider causes a fatal `Error` during Laravel's boot sequence. All subsequent service providers in the boot order are never registered, and the application fails to start entirely.

---

- **Severity:** High
- **Category:** Architecture
- **Location:** `src/Core/Module/ModuleRegistry.php:78–83`
- **Description:** `invalidateAllTenants()` clears only the in-memory `$tenantActive` array. It explicitly skips the distributed cache, as noted in the comment: "No pattern-delete available in all drivers." When `ModuleRemover` or `ModuleUpgrader` calls this method, all per-tenant keys in Redis/Memcached/file cache remain until natural TTL expiry.
- **Risk:** After a module is removed, all tenants that had it active continue to receive "active" responses from `activeFor()` for up to 3600 seconds. Module providers may boot, routes may register, and the module functions normally despite being removed from the platform.

---

- **Severity:** High
- **Category:** Performance
- **Location:** `src/Console/Commands/ListModulesCommand.php:27`
- **Description:** `TenantModule::forModule($slug)->active()->count()` is executed inside `foreach ($registry->allDiscovered() as $slug => $manifest)`. This issues one `COUNT(*)` SQL query per discovered module, with no batching or caching.
- **Risk:** With N discovered modules, `ListModulesCommand` generates N+1 database queries. On platforms with 50+ modules, this causes significant latency and database load for a listing operation.

---

- **Severity:** High
- **Category:** Performance
- **Location:** `src/Core/Lifecycle/ModuleDeactivator.php:37–44` and `51–59`
- **Description:** Both `deactivateAll()` and `deactivateAllForTenant()` collect a list of IDs/slugs in one query, then call `$this->deactivate()` in a loop. Each `deactivate()` call issues an individual `UPDATE` query, a `Cache::forget()` call, and an event dispatch. For K tenants or K modules, this is O(K) individual UPDATE queries with O(K) cache and event operations.
- **Risk:** A module with 1,000 active tenants triggers 1,000 sequential UPDATE queries, 1,000 cache deletions, and 1,000 event dispatches when removed with `--force`. In high-tenant systems this can exhaust database connections and cause request timeouts.

---

- **Severity:** High
- **Category:** Security
- **Location:** `src/Core/Permissions/Drivers/NullPermissionDriver.php:22`
- **Description:** `userCan()` unconditionally returns `true`. The driver is selectable via the `MODULARITY_PERMISSION_DRIVER=null` environment variable and is not restricted to test environments at the framework level.
- **Risk:** If `null` is set as the permission driver in any non-test environment — by accidental misconfiguration, a copied `.env`, or a deployment error — all `userCan()` checks across the application return `true`. Any user can perform any action protected by Modularity's permission system.

---

## 🟡 Medium Issues

---

- **Severity:** Medium
- **Category:** Security
- **Location:** `src/Core/Module/ModuleLoader.php:151–155`
- **Description:** `$name = $package['name'] ?? ''` is read from `vendor/composer/installed.json`, then used directly in `$vendorDir = base_path('vendor/'.$name)`. The package name is not validated against `..` sequences or unexpected path separators before being used in filesystem path construction.
- **Risk:** A `vendor/composer/installed.json` modified to contain a package name such as `"../../config"` would cause `$vendorDir` to resolve outside the vendor directory. If a `module.json` exists at that resolved path, it would be parsed and potentially booted. This is relevant in shared hosting, CI artifact injection, or supply chain scenarios.

---

- **Severity:** Medium
- **Category:** Security
- **Location:** `src/Core/Permissions/Drivers/GatePermissionDriver.php:30–32`
- **Description:** `allForTenant(int $tenantId)` ignores the `$tenantId` parameter entirely and returns `array_keys($this->registered)` — the complete global list of all permissions registered by all modules across all tenants in the current PHP process.
- **Risk:** Callers expecting tenant-scoped permissions receive a superset that includes permissions from modules active for other tenants. Applications using this output to build permission lists or ACL UIs will leak cross-tenant permission metadata.

---

- **Severity:** Medium
- **Category:** Security
- **Location:** `src/Core/Permissions/Drivers/SpatiePermissionDriver.php:25–28`
- **Description:** `allForTenant(int $tenantId)` executes `\Spatie\Permission\Models\Permission::pluck('name')->all()` — a full table scan that ignores the `$tenantId` argument and returns all permissions from all modules across all tenants.
- **Risk:** Same cross-tenant permission leakage as the Gate driver, compounded by the fact that Spatie permissions are persistent in the database and can include permissions from deactivated or removed modules unless cleaned up manually.

---

- **Severity:** Medium
- **Category:** Error
- **Location:** `src/Console/Commands/MakeLivewireCommand.php:82–88` (`writeFromStub` method)
- **Description:** `$this->files->get($stub)` is called without first checking `$this->files->exists($stub)`. Compare with `MakeModuleCommand.php:120` which guards stub access with an existence check. `Filesystem::get()` throws `FileNotFoundException` when the target file does not exist.
- **Risk:** If Livewire stub files are missing (e.g., after `artisan vendor:publish` is not run), the command throws an unhandled exception. Any partial output written before the exception — a class file or a view file — leaves the module in a half-scaffolded state.

---

- **Severity:** Medium
- **Category:** Architecture
- **Location:** `src/Core/Module/ModuleLoader.php:40–41`
- **Description:** In CLI context (`runningInConsole()`), `$candidates = array_values($discovered)` includes all discovered modules regardless of whether they are installed. All their providers are then registered (line 103) during every artisan invocation.
- **Risk:** Module providers for uninstalled modules (no migrations run, no DB record) are booted during CLI operations. A provider that accesses its own tables in `boot()` will cause SQL errors before `module:install` has been run. This creates a circular dependency: artisan commands needed to install the module crash because the uninstalled module's provider is being booted.

---

- **Severity:** Medium
- **Category:** Data Integrity
- **Location:** `src/Core/Module/MigrationRunner.php:16–32`
- **Description:** `pendingForModule()` queries `ModuleMigrationLog` to determine which migrations have not yet run, then `Artisan::call('migrate', ...)` executes all migrations in the path directory. These are two separate non-atomic operations. A concurrent `module:install` or `module:upgrade` call can independently determine the same set of migrations as pending and both invoke the Artisan migrator.
- **Risk:** Both processes run the same migrations concurrently. The `UNIQUE` constraint on `(module_slug, migration_file)` in `modularity_migration_log` prevents duplicate log entries but does not prevent duplicate DDL execution. Concurrent `CREATE TABLE` statements can produce database errors that leave both processes in a failed state.

---

- **Severity:** Medium
- **Category:** Architecture
- **Location:** `src/Core/Module/ManifestDTO.php:15` and `src/Core/Module/ModuleManifest.php:47`
- **Description:** The `compatibility` field is parsed from `module.json` and stored in `ManifestDTO`, but it is never compared against `config('modularity.version')` anywhere in the codebase — not at install, boot, or upgrade time.
- **Risk:** Modules declaring `"compatibility": "^0.5"` on a `1.0.0` platform will be discovered, installed, and booted without any warning or rejection. Breaking API changes between platform versions cause silent runtime failures rather than controlled rejection at install time.

---

- **Severity:** Medium
- **Category:** Security
- **Location:** `src/Core/Lifecycle/ModuleInstaller.php:43`
- **Description:** `md5_file($manifest->path.'/module.json')` is stored as the module integrity checksum. MD5 is a cryptographically broken hash function. Furthermore, this checksum is stored in the `InstalledModule` record and never read back or compared anywhere in the codebase — not in `ModuleUpgrader`, `ModuleLoader`, or any other integrity check path.
- **Risk:** The stored checksum provides no actual integrity guarantee. A tampered `module.json` will not be detected. The use of MD5 also means that if verification is added later, it remains trivially defeatable by collision.

---

- **Severity:** Medium
- **Category:** Observability
- **Location:** `src/Core/Module/MigrationRunner.php:22–26`
- **Description:** `Artisan::call('migrate', ['--path' => ..., '--realpath' => true, '--force' => true])` discards all command output. The Artisan exit code is not captured or checked. No log entries record which specific migration files were executed, what schema changes were applied, or whether any warnings were emitted.
- **Risk:** When a migration fails silently, there is no log trail to aid debugging. In production, operators cannot determine which migrations ran for a module, what the migration output was, or why the module is in an errored state without direct database inspection.

---

- **Severity:** Medium
- **Category:** Observability
- **Location:** `src/Console/Commands/InstallModuleCommand.php:38–41`, `RemoveModuleCommand.php:41–43`, `UpgradeModuleCommand.php:28–30`
- **Description:** The catch-all `\Exception $e` blocks in all lifecycle commands output `$e->getMessage()` to the console but do not write anything to the application log. Production deployments that run these commands via CI/CD pipelines or scheduled jobs produce no log record of failures.
- **Risk:** Silent production failures. If `module:install` fails during a deployment, the only record of the error is the console output of that specific process invocation. Application log aggregation systems (Datadog, Papertrail, CloudWatch) will have no signal and no alert.

---

## 🔵 Low Issues

---

- **Severity:** Low
- **Category:** Security
- **Location:** `src/Core/Tenancy/Resolvers/HeaderTenantResolver.php:14`
- **Description:** `ctype_digit("0")` returns `true`, so `X-Tenant-ID: 0` passes validation and resolves to tenant ID `0`. `TenantContext::isSet()` returns `true` for `0` since the stored property is `?int` and `0 !== null`.
- **Risk:** In applications where tenant IDs are auto-incremented from 1, tenant 0 does not legitimately exist. However, `TenantScope` queries will issue `WHERE tenant_id = 0`, and any records with `tenant_id = 0` (e.g., seed data) become accessible. Code that calls `$context->isSet()` to determine whether a tenant is resolved will incorrectly receive `true`.

---

- **Severity:** Low
- **Category:** Data Integrity
- **Location:** `database/migrations/2024_01_01_000003_create_modularity_tenant_module_subscriptions_table.php:18`
- **Description:** The `modularity_tenant_module_subscriptions` table has `->index(['tenant_id', 'module_slug'])` but no `->unique(['tenant_id', 'module_slug'])` constraint. Multiple subscription records for the same tenant and module can coexist without any database-level enforcement.
- **Risk:** When a real subscription manager queries this table, it may receive multiple rows and resolve ambiguously. Duplicate subscriptions can accumulate silently, creating billing discrepancies that are invisible until reconciliation.

---

- **Severity:** Low
- **Category:** Data Integrity
- **Location:** `database/migrations/2024_01_01_000002_create_modularity_tenant_modules_table.php` and `_000003_`
- **Description:** `tenant_id` columns across all Modularity tables are `unsignedBigInteger` with no `->foreign()` reference to any tenant table. `module_slug` columns have no foreign key reference to `modularity_installed_modules.slug`.
- **Risk:** Orphaned records accumulate when tenants are deleted without invoking `deactivateAllForTenant()`, or when modules are removed via `ModuleRemover` (which deletes the `InstalledModule` record but leaves `TenantModule` and `TenantModuleSubscription` rows). Without FK constraints, these go undetected and can corrupt future queries or reinstall operations.

---

- **Severity:** Low
- **Category:** Code Quality
- **Location:** `src/Models/InstalledModule.php:9, 17–19`
- **Description:** `$timestamps = false` disables Eloquent's automatic timestamp management, but `installed_at` is in `$fillable`. The migration uses `->useCurrent()` to set the DB-level default. This allows callers to pass arbitrary `installed_at` values via mass assignment, and the relationship between the model, the DB default, and the fillable property is not enforced at the application level.
- **Risk:** `installed_at` can be set to arbitrary values via `InstalledModule::create(['installed_at' => '2000-01-01'])`. This affects audit trails and diagnostic output in `module:status`, making installation timestamps an unreliable data point.

---

- **Severity:** Low
- **Category:** Architecture
- **Location:** `src/Core/Navigation/NavigationRegistry.php:30` and `src/Core/Tenancy/TenantScope.php:13, 26`
- **Description:** `$manager = app(\Modularity\Core\Module\ModuleManager::class)` is called inside a collection filter closure in `NavigationRegistry::forTenant()`. `$context = app(TenantContext::class)` is called in both `TenantScope::apply()` and the static `TenantScope::creating()`. These resolve dependencies via service locator rather than constructor injection.
- **Risk:** These classes are not testable in isolation without a fully booted Laravel application container. Static analysis tools cannot detect missing bindings at definition time. The static `creating()` method on `TenantScope` cannot receive injection at all, permanently coupling it to the global `app()` helper.

---

- **Severity:** Low
- **Category:** Code Quality
- **Location:** `src/Console/Commands/MakeModuleCommand.php:120–121`
- **Description:** When a stub file does not exist, the loop silently skips it with `continue`. The module is scaffolded with missing files and the user receives no warning about which files were omitted.
- **Risk:** A module scaffolded from an incomplete or partially published stub set will be missing controllers, providers, or route files. The user's next step (`module:install`) may succeed silently with a broken module, or fail with confusing errors that are only traceable by inspecting the module directory manually.

---

- **Severity:** Low
- **Category:** Observability
- **Location:** `src/Core/Module/ModuleLoader.php:55–58` / `src/Core/Module/Exceptions/CircularDependencyException.php:9–14`
- **Description:** On `CircularDependencyException`, the logged slugs are those with a non-zero in-degree from Kahn's algorithm — an unordered set of involved nodes. The error message does not convey the actual edge path forming the cycle (e.g., `A → B → C → A`), only the participants.
- **Risk:** Operators diagnosing a circular dependency must manually inspect all module manifests to determine the exact dependency chain causing the cycle. With many modules, this is operationally expensive and slows incident resolution.

---

- **Severity:** Low
- **Category:** Data Integrity
- **Location:** `src/Core/Lifecycle/ModuleInstaller.php:53` and `src/Listeners/CacheInvalidationListener.php:17–19`
- **Description:** `ModuleInstaller::install()` calls `$this->registry->invalidateInstalled()` on line 53, then dispatches `ModuleInstalled` on line 55. The registered `CacheInvalidationListener::handleModuleInstalled()` also calls `invalidateInstalled()`. The same invalidation executes twice in sequence on every install operation.
- **Risk:** Redundant double cache invalidation and double DB re-population on each install. In bulk install scenarios (seeding, mass deployment), this doubles unnecessary cache operations. It also indicates an unresolved overlap in responsibility between the lifecycle class and the event listener.
