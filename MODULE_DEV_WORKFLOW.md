# Module Development Workflow — Implementation Plan

> Goal: develop and test Modularity **modules** as standalone, publishable packages
> with the same lightweight loop the **core** package already uses (Orchestra
> Testbench, no full Laravel app), and turn **module-demo** into a reusable
> playground where any module can be dropped in, installed, and exercised in a real
> Laravel request.

## The three development loops

| Tier | Code | How it's developed/tested | Dependency footprint |
|------|------|---------------------------|----------------------|
| **Core** (`modularity/core`) | the engine | Testbench + fixtures (✅ already works) | testbench only |
| **Module** (`modules/invoice`, …) | standalone composer package that `require`s the core | Testbench, booting core + this one module | testbench + `modularity/core` |
| **Host / playground** (`module-demo`) | a real Laravel app | full app — integration & manual QA only | full Laravel |

Guiding principle: **testbench for the inner loop, `module-demo` for the "does it
survive a real request" outer loop.** Never reach for the full app to test module
logic; never trust testbench alone for HTTP/session/middleware behavior.

---

## Current state (what already exists)

- `tests/TestCase.php` — Testbench base for the core: registers
  `ModularityServiceProvider`, in-memory SQLite, disables cache, `null` perms.
- `tests/HttpTestCase.php` — boots through real requests: sets `app.key`, real
  `gate` driver, points `modules_path` at `tests/Fixtures/modules`. Resets the
  registry between tests.
- `src/Testing/InteractsWithModules.php` — install/activate/tenant helpers shipped
  *from the package* (`installModule`, `activateModule`, `installAndActivateModule`,
  `bootModules`, `asTenant`).
- `tests/Fixtures/modules/widget/` — proof a module can live on disk and be booted
  (`module.json` + `routes/web.php`).
- `stubs/module/tests/FeatureTest.stub` — generated starter test (uses
  `InteractsWithModules`, but assumes a **host app's `Tests\TestCase` exists** ← the
  one assumption that drags the full app back in).
- `MakeModuleCommand` — generates a module **into a host app's `Modules/` dir**
  (in-app layout: `src/`, `routes/`, `database/`, `tests/`, `module.json`).
- Discovery (`ModuleLoader`) already supports **two** module sources:
  1. **Local path modules** — any dir under `modules_path` with a `module.json`.
  2. **Composer modules** — packages in `vendor/composer/installed.json` flagged
     with `extra.modularity.module = true` and shipping a `module.json`.

This dual discovery is the key enabler for the reusable playground.

---

## Workstream 1 — Ship the test base from the package

**Why:** a standalone module repo must be able to boot core + itself with *no* host
app. Today the only Testbench base lives in the core's own `tests/` (not shipped) and
the stub points at `Tests\TestCase` from a host.

**Deliverables (new files under `src/Testing/`):**

1. `src/Testing/ModuleTestCase.php`
   - Abstract, extends `Orchestra\Testbench\TestCase`.
   - Generalize `tests/TestCase.php`: register `ModularityServiceProvider`, package
     aliases, in-memory SQLite, `cache.enabled=false`, `permissions.driver=null`.
   - Add an overridable `protected function modulePath(): string` (default: walk up
     from the test file / `getcwd()` to the package root) and set
     `modularity.modules_path` to **the module-under-test's own directory** so the
     loader discovers it as a local module.
   - Override `getPackageProviders()` so the consuming module can append its own
     provider(s) without rewriting boot logic.

2. `src/Testing/ModuleHttpTestCase.php`
   - Extends `ModuleTestCase`, mirrors `tests/HttpTestCase.php`: sets `app.key`,
     real `gate` driver, `RefreshDatabase`, `InteractsWithModules`, and the
     `invalidateInstalled()` reset in `setUp()`.
   - Document the testbench HTTP limits (see Caveat below) in the class docblock.

3. Refactor the core's own `tests/TestCase.php` / `tests/HttpTestCase.php` to extend
   these new shipped bases (eat our own dog food; keeps one source of truth).

4. Update `stubs/module/tests/FeatureTest.stub` to offer **two** modes:
   - In-app module → `uses(Tests\TestCase::class, …)` (current behavior).
   - Standalone module → `uses(Modularity\Testing\ModuleTestCase::class, …)`.
   - Decide via the generator flag in Workstream 2.

**Acceptance:** a fresh module repo with only `orchestra/testbench` + `modularity/core`
as dev deps can run `vendor/bin/pest` green, with no `app/`, no `bootstrap/`, no host.

---

## Workstream 2 — Standalone (publishable) module skeleton

**Why:** `MakeModuleCommand` only produces the in-app layout. Publishable modules
need their own `composer.json`, `phpunit.xml`, and a self-contained test setup.

**Approach:** add a `--standalone` flag (alias: a thin `module:new-package` command)
to `MakeModuleCommand`. When set, scaffold a **publishable package repo** instead of
an in-app folder.

**Standalone layout to generate:**
```
my-module/
├── composer.json          # name, require modularity/core, require-dev testbench+pest,
│                          #   PSR-4 autoload Modules\Name\ -> src/, extra.modularity.module=true
├── module.json            # manifest (providers point at the package namespace)
├── phpunit.xml            # single testsuite -> tests/
├── src/                   # same internal structure as in-app modules
│   ├── Providers/NameServiceProvider.php
│   ├── Http/Controllers/…
│   ├── Models/…
│   └── …
├── routes/{web,api}.php
├── database/migrations/…
├── resources/views/…
├── tests/
│   ├── Pest.php           # uses Modularity\Testing\ModuleTestCase
│   └── NameTest.php       # from FeatureTest.stub (standalone mode)
└── README.md
```

**New stubs needed** (under `stubs/module-package/` to keep them separate from the
in-app `stubs/module/`):
- `composer.json.stub` — note `extra.modularity.module = true` so composer-installed
  consumers are auto-discovered; `require-dev` testbench/pest; PSR-4 mapping.
- `phpunit.xml.stub`
- `Pest.php.stub` (wires `ModuleTestCase` + `RefreshDatabase` + `InteractsWithModules`)
- `README.stub`
- Reuse existing `src/`, `routes/`, `database/`, `resources/`, `module.json` stubs.

**Generator changes (`MakeModuleCommand`):**
- Branch on `--standalone`: different base path default (cwd, not `modules_path`),
  different stub set, write `composer.json`/`phpunit.xml`, emit standalone "next
  steps" (`composer install`, `vendor/bin/pest`).
- Keep the existing in-app path 100% unchanged when the flag is absent.

**Tests:** extend `tests/Feature/Commands/MakeModuleCommandTest.php` with a
`--standalone` case asserting the package files exist and PSR-4/`extra.modularity`
are present.

**Acceptance:** `php artisan module:make-module Invoice --standalone` produces a repo
that is `composer install && vendor/bin/pest`-green out of the box and is
`composer require`-installable into any host.

---

## Workstream 3 — Make `module-demo` a reusable playground

**Why:** developers want to drop in *any* module — local folder or published package
— install it, activate it for a tenant, and click through it in a real app. Today the
demo hardcodes `Customer`/`Invoice` PSR-4 autoload entries, so arbitrary modules
won't autoload.

**Two supported install paths (both already discovered by `ModuleLoader`):**

A. **Drop-in local module** → copy/symlink a module folder into `Modules/`.
   - Friction: PSR-4 autoload must exist. Hardcoded entries don't scale.
   - **Fix:** add a **wildcard / classmap-free PSR-4 strategy** for `Modules/*`.
     Options, in order of preference:
     1. A small `composer.json` `autoload.psr-4` convention + a generated mapping
        refreshed by a helper command (`module:link`) that registers
        `Modules\\<Name>\\` → `Modules/<Name>/src/` and runs `dump-autoload`.
     2. Document the manual two-line add + `composer dump-autoload`.
   - Provide `php artisan module:link {path}` (new, demo-side or package-side) that:
     copies/symlinks the source into `Modules/`, patches `composer.json` autoload,
     dumps autoload, then runs `module:install` (+ optional `module:activate`).

B. **Published / path-repo package** → `composer require vendor/module` (or a path
   repository entry pointing at a local checkout). `extra.modularity.module=true`
   makes it auto-discovered; then `module:install` + `module:activate`.
   - This is the cleanest path and needs **no** autoload patching — recommend it as
     the default for testing standalone packages built in Workstream 2.

**Playground UX to add to the demo:**
- A documented `.env`/seeder with at least two tenants + a user per tenant (so
  tenant isolation and `module.active` gating are observable). The "HTTP rough edges"
  memo already flags session/auth wiring — bake the working version into the demo.
- A "modules" admin screen (or just `php artisan module:list`) showing
  discovered/installed/active state per tenant.
- A `composer playground:reset` script: fresh migrate, seed tenants, no modules
  installed — clean slate to drop the next module into.
- README section: "Test a module here" with both path A and path B step-by-step.

**Acceptance:** given any module (in-app folder *or* standalone package), a developer
can, in ≤3 commands, get it installed, activated for a tenant, and reachable at its
route in the running demo — without editing demo source by hand (path B) or with a
single `module:link` command (path A).

---

## Workstream 4 — Documentation

- **INTEGRATION.md / README**: add "The three development loops" table and the rule
  (testbench inner loop, demo outer loop, demo is integration-only).
- **A `docs/developing-modules.md`**: end-to-end — scaffold standalone → test with
  Testbench → publish → install into demo/host. Cross-link `ModuleTestCase`,
  `InteractsWithModules`, and the `module.json`/`extra.modularity` discovery contract.
- Note the manifest ↔ composer discovery duality so authors know *why* both a
  `module.json` and `extra.modularity.module=true` are needed for the package path.

---

## The one caveat to keep flagging

Testbench gives a fast, true loop for module **logic, lifecycle, and tenancy
scoping**. It does **not** fully reproduce a real app's HTTP stack — cookie
encryption, session driver, middleware group ordering, boot-time gating (exactly the
issues already logged in the "HTTP rough edges" memo, which `HttpTestCase` works
around with pre-boot config). So:

- Use `ModuleTestCase` / `ModuleHttpTestCase` for the inner loop.
- Use `module-demo` for the "real request" outer loop before publishing.
- Don't try to make Testbench carry 100% of HTTP fidelity — that's the only way this
  strategy bites you.

---

## Suggested sequencing

1. **WS1** (test base) — unblocks everything; smallest, highest leverage.
2. **WS2** (standalone skeleton) — depends on WS1's `ModuleTestCase`.
3. **WS3** (reusable demo) — independent of WS1/WS2 for path B; `module:link` for
   path A can come last.
4. **WS4** (docs) — finalize after the surfaces above stabilize.

## Open decisions to confirm before building

- `--standalone` flag on `MakeModuleCommand` vs. a separate `module:new-package`
  command (lean: flag, less surface).
- Where `module:link` lives — shipped in the package (useful to all hosts) vs.
  demo-only. (Lean: package-side, it's generally useful.)
- Whether to slim down `module-demo` (remove Customer/Invoice as fixed modules and
  make them *installable samples* instead) so the playground starts empty.
