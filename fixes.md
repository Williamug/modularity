# Fixes & Usability Improvements for modularity/core

## Overview
This document outlines concrete fixes to improve usability, reduce friction for newcomers, and make `modularity/core` easier to adopt.

---

## Critical Issues (fix first)

### 1. Missing `$slug` causes cryptic runtime error
**Problem:** Forgetting `protected string $slug` in `ModuleServiceProvider` causes a runtime error that doesn't point to the real issue.

**Current behavior:**
```
Error: Call to undefined method ... in ModuleServiceProvider::boot()
```

**Fix:**
- Add validation in `ModuleServiceProvider::boot()` to assert `$slug` is set.
- Throw clear `InvalidModuleException` with message: "ModuleServiceProvider must define `protected string $slug = 'your-slug'`"
- Include the exact code snippet in the error.

**Effort:** Low (1-2 hours)

---

### 2. No beginner-friendly Quickstart
**Problem:** Developers start with AGENTS.md or README.md, which are expert-level. No 5-minute "Hello World" module guide.

**Fix:**
- Create `docs/quickstart.md` (target: 10 min read).
- Show exact commands:
  ```bash
  php artisan module:make-module Library
  php artisan module:install library
  php artisan module:activate library --tenant=1
  php artisan tinker
  # Now: Module::active('library') returns true
  ```
- Include a minimal model example (`Book extends ModuleModel`).
- Link to "Next Steps" for deeper learning.

**Effort:** Medium (4-6 hours, includes screenshots/examples)

---

### 3. Error messages don't suggest fixes
**Problem:** When validation fails (e.g., slug format, missing dependency), the error doesn't tell you how to fix it.

**Current:**
```
InvalidManifestException: Slug must be kebab-case
```

**Fix:**
```
InvalidManifestException: Slug must be kebab-case (e.g., 'my-module', not 'MyModule').
Suggestion: Update "slug" in module.json to match the pattern /^[a-z0-9]+(?:-[a-z0-9]+)*$/
Current value: 'MyModule'
```

For dependency errors:
```
DependencyNotInstalledException: Module 'invoicing' requires 'core-billing' to be installed.
Suggestion: Run: php artisan module:install core-billing
```

**Effort:** Medium (5-8 hours, need to enhance all exception classes)

---

### 4. `tenant_id` requirement not obvious in migration stubs
**Problem:** When generating migrations, the stub doesn't include `tenant_id` by default, causing data isolation failures.

**Current stub:**
```php
Schema::create('books', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->timestamps();
});
```

**Fix:**
- Update `stubs/module/migration.create.stub` to include `tenant_id`:
```php
Schema::create('{{table}}', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('tenant_id')->index();
    $table->timestamps();
});
```
- Add a comment explaining why:
```php
// $table->unsignedBigInteger('tenant_id')->index();  // Required for multi-tenant data isolation
```
- Document in module generator output: "Remember: this module is multi-tenant. Add tenant_id to all data tables."

**Effort:** Low (2-3 hours)

---

### 5. `Module::config()` silently returns default without tenant
**Problem:** If you call `Module::config('library', 'limit', 100)` without `Tenant::id()` set, it returns `100` silently — no error, no warning. This is dangerous in CLI commands.

**Fix:**
- Add optional strict mode in config: `MODULARITY_STRICT_TENANT_CONFIG=true`
- When enabled, throw `TenantNotResolvedException` if called without a tenant.
- Add guard helper: `Module::configFor($slug, $tenantId, $key, $default)` that's explicit.
- Document when to use each pattern.

**Effort:** Medium (4-6 hours)

---

## Usability Improvements (do after critical fixes)

### 6. Better scaffold stubs
**Problem:** Generated stubs are bare-bones; developers copy-paste from docs instead of using them.

**Fix:**
- Add opinionated, working examples to all stubs:
  - `ServiceProvider.stub`: include navigation registration example (not just comment).
  - `Model.stub`: extend `ModuleModel` by default (not `Model`).
  - `Controller.stub`: show a basic CRUD with `Module::active()` check.
  - `migration.create.stub`: include `tenant_id` + comment explaining why.
  - Add `Policy.stub` with tenant-scoped examples.

**Effort:** Medium (6-8 hours)

---

### 7. One-command shortcuts for common workflows
**Problem:** Newcomers must run 3 commands to test a module: `make`, `install`, `activate`.

**Fix:**
- Add `module:create-and-install <Name> --activate --tenant=1` to collapse steps.
- Example:
  ```bash
  php artisan module:create-and-install Library --activate --tenant=1
  # Runs: make-module, install, activate (all in one)
  ```

**Effort:** Medium (4-5 hours)

---

### 8. Interactive wizard for `module:make-module`
**Problem:** `--help` is long; new users don't know what flags to use.

**Fix:**
- Add `--interactive` flag or make it default:
  ```
  $ php artisan module:make-module --interactive
  Module name: Library
  Module slug (default: library): library
  Add migrations? (yes/no) [yes]: yes
  Add models? (yes/no) [yes]: yes
  Use Livewire? (yes/no) [no]: no
  ```

**Effort:** Medium (5-7 hours)

---

### 9. Validation helper for module.json
**Problem:** Users hand-edit `module.json` and break it; no validation until install.

**Fix:**
- Add command: `php artisan module:validate <slug>` to validate manifest before install.
- Better yet, auto-validate on each scaffold and warn if invalid.
- Output clear report:
  ```
  Validating module 'library'...
  ✓ Slug format valid
  ✓ Providers declared
  ✗ 'invoicing' is a dependency but not installed
  Suggestion: Run php artisan module:install invoicing first
  ```

**Effort:** Medium (5-6 hours)

---

### 10. Better test setup documentation & helpers
**Problem:** Setting up tests requires knowing about `Tenant::set()`, `InteractsWithModules`, and test fixtures.

**Fix:**
- Add `docs/testing.md` with copy-paste examples.
- Extend test stub to include trait + setup:
```php
uses(Tests\TestCase::class, RefreshDatabase::class, InteractsWithModules::class);

beforeEach(fn () => $this->installAndActivateModule('library', tenantId: 1));

it('scopes data per tenant', function () {
    // test code
});
```
- Add helper to scaffold tests: `module:generate-tests <slug>`.

**Effort:** Medium (4-5 hours)

---

## Documentation Improvements

### 11. Clarify when `boot()` runs and why
**Problem:** The rule "boot() runs for every installed module" is not intuitive to newcomers.

**Fix:**
- Add section to README: "Understanding Module Boot Lifecycle"
- Diagram showing: `boot() → module.active middleware → request-time gating`
- Example: "Your module's routes are registered for every installed module, but the `module.active:library` middleware prevents unauthorized access at request time."

**Effort:** Low (2-3 hours, mostly docs)

---

### 12. Migration guide from `nwidart/laravel-modules`
**Problem:** Existing `nwidart` users don't know how to convert or if it's worth it.

**Fix:**
- Create `docs/migration-from-nwidart.md`:
  - Side-by-side comparison of APIs.
  - Mapping of `nwidart` commands → `modularity` commands.
  - Script to auto-convert module structure.
  - When to migrate (single-tenant → multi-tenant SaaS).

**Effort:** Medium (6-8 hours)

---

### 13. FAQ for common mistakes
**Problem:** Same issues repeat in GitHub issues.

**Fix:**
- Create `docs/faq.md` covering:
  - "My module's routes return 404" → check `module.active` middleware
  - "Data is visible across tenants" → add `tenant_id` to migrations + use `BelongsToTenant`
  - "Module::config() returns default unexpectedly" → check `Tenant::isSet()`
  - "`$slug` not working" → ensure it matches module.json exactly
  - "Migration fails after upgrade" → understand migration idempotency

**Effort:** Low (3-4 hours)

---

## Quality-of-Life Improvements

### 14. Better CLI output & colors
**Problem:** CLI output is verbose and hard to scan.

**Fix:**
- Add color to `module:list` output (active = green, inactive = yellow, failed = red).
- Add progress indicators for long operations (install, upgrade).
- Example:
  ```
  Installing library...
  ✓ Database migrations
  ✓ Permissions registered
  ✓ Navigation loaded
  Module 'library' installed successfully.
  ```

**Effort:** Low (3-4 hours)

---

### 15. Module generator dry-run
**Problem:** Users want to see what `module:make-module` will create before committing.

**Fix:**
- Add `--dry-run` flag to scaffold commands.
- Output file list without creating files.

**Effort:** Low (2-3 hours)

---

## Performance & Production Readiness

### 16. Cache invalidation & refresh commands
**Problem:** Cache can get stale; no easy way to clear it.

**Fix:**
- Add `module:cache` to pre-warm registry.
- Add `module:clear-cache` to force refresh.
- Document cache invalidation on `boot()` for development.

**Effort:** Low-Medium (3-4 hours)

---

### 17. Health check command
**Problem:** No way to verify module health before deploying.

**Fix:**
- Add `module:health` to check:
  - All installed modules' manifests are valid.
  - Dependencies are met.
  - Migrations are current.
  - Permissions are registered.
  - No orphaned `TenantModule` records.

**Effort:** Medium (5-6 hours)

---

## Summary Table

| Issue | Priority | Effort | Impact |
|-------|----------|--------|--------|
| Missing `$slug` validation | Critical | Low | High (prevents runtime errors) |
| No Quickstart docs | Critical | Medium | High (improves onboarding) |
| Poor error messages | Critical | Medium | High (reduces confusion) |
| `tenant_id` not in stubs | Critical | Low | High (prevents data leaks) |
| `Module::config()` silent failures | Critical | Medium | Medium |
| Better stubs | High | Medium | High (improves DX) |
| One-command shortcuts | High | Medium | High (faster prototyping) |
| Interactive wizard | High | Medium | Medium (easier setup) |
| Validation helper | High | Medium | Medium |
| Test docs & helpers | High | Medium | High (better test adoption) |
| Boot lifecycle docs | High | Low | Medium (reduces confusion) |
| `nwidart` migration guide | High | Medium | Medium (increases adoption) |
| FAQ | High | Low | Medium (self-serve support) |
| CLI colors/output | Medium | Low | Low (UX polish) |
| Dry-run flag | Medium | Low | Low (nice to have) |
| Cache commands | Medium | Low-Medium | Low-Medium (ops tooling) |
| Health check | Medium | Medium | Medium (prod readiness) |

---

## Next Steps

1. **Week 1:** Fix critical issues (#1, #2, #3, #4).
2. **Week 2–3:** Implement usability improvements (#6, #7, #10).
3. **Week 4:** Documentation + FAQ.
4. **Beyond:** Polish and ops tooling.
