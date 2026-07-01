Future roadmap and feature list for modularity/core
===============================================

Summary
-------
This file captures prioritized improvements to make `modularity/core` more competitive and easier to adopt, especially versus `nwidart/laravel-modules`.

Top features (prioritized)
--------------------------
- Single-tenant / dev mode: opt-in mode to simplify onboarding and local development.
- One-command scaffolding: `module:create-and-install`, `module:install-and-activate` shortcuts.
- Improved CLI & stubs: flags for `--with-tests`, `--with-assets`, and single-tenant-ready templates.
- Docs & Quickstart: a 5-minute Quickstart, migration guide from `nwidart`, and troubleshooting FAQ.
- Asset publishing parity: `module:publish-assets` and npm-friendly stubs for frontend tooling.
- Migration/import tool: `module:import-nwidart` to convert `nwidart` modules into `modularity` format.
- Example modules & demos: `examples/` with 2–3 complete modules (CRUD, Livewire, assets).
- Admin UI: minimal web UI (or Nova/Laravel-Admin plugin) for install/activate/deactivate per tenant.
- Testing ergonomics & CI templates: better test helpers, example pipelines, and `InteractsWithModules` examples.
- Performance & boot optimization: lazy-boot options, cache priming command, and profiling guidance.

90-day roadmap (high level)
--------------------------
1. Docs & Quickstart (Week 1–2): publish a clear Quickstart and migration HOWTO.
2. Single-tenant/dev mode (Week 2–4): implement opt-in dev mode and update stubs.
3. CLI & stubs (Week 3–6): add scaffolding flags and one-command flows.
4. Example modules (Week 4–8): add `examples/library` and `examples/invoicing` modules.
5. Migration/import tool (Week 6–12): create converter for `nwidart` modules.

Quick wins (one-PR tasks)
------------------------
- Add `--single-tenant` flag to `module:make-module` and matching stubs.
- Add `examples/` with a small working module demonstrating migrations, menu, and assets.
- Create a `docs/quickstart.md` with five CLI steps to go from zero → module installed.

Developer ergonomics
--------------------
- Make manifest validation errors actionable (suggest exact CLI to fix).
- Provide `module:install --auto-activate --tenant=1` shortcut to collapse steps for newcomers.
- Include test helpers that auto-boot modules when installed during tests.

Community & adoption
--------------------
- Ship video demos, migration guides, and blog posts showing real SaaS scenarios.
- Provide migration path and tooling to lower switching cost from `nwidart`.
- Offer sample admin UI and marketplace stubs to demonstrate Phase-2 features.

Risks & guardrails
------------------
- Keep single-tenant/dev mode opt-in and clearly documented to avoid production misuse.
- Preserve tenant isolation invariants — never make `tenant_id` optional silently.

Additional features ideas
------------------------

Core SaaS features
------------------
- Subscriptions & Billing: built-in subscription plans, trial/renewal hooks, invoice hooks.
- Quota & Rate Limits: per-tenant quotas (API, storage, features) with enforcement and soft-fail modes.
- Per-tenant Feature Flags: toggle features per-tenant with rollout controls and audit trail.
- Per-tenant Theming / Branding: tenant-level UI customization (logos, colors, domain mapping).

Developer experience
--------------------
- One-step Migration Helpers: automated helpers to add `tenant_id` to existing migrations and convert `nwidart` modules.
- Interactive CLI Wizard: guided `module:create` and `module:install` wizards (fills `$slug`, dependencies).
- VS Code Extension / Snippets: scaffolding snippets and commands for faster module creation.
- Module Marketplace SDK: libraries and sample code for publishing modules to a marketplace.

Extensibility & ecosystem
------------------------
- Plugin System: allow third-party plugins that extend lifecycle hooks without editing core.
- Module Dependency Graph UI: visualize dependencies, detect cycles, and suggest install order.
- Module Versioning & Compatibility Checks: semantic compatibility checks before install/upgrade.

Admin & UX
--------
- Web Admin Console: UI to discover, install, upgrade, activate, and audit modules per-tenant.
- Activity / Audit Logs: tenant/module lifecycle audit logs and exportable reports.
- Role & Permission Templates: opinionated permission templates per module for faster setup.

Operations & reliability
----------------------
- Migration Safety Tools: dry-run upgrade, preview pending migrations, and per-module migration logs.
- Health Checks & Telemetry: module-level health endpoints, usage metrics, and Prometheus-friendly metrics.
- Zero-downtime Upgrade Helpers: helpers for rolling upgrades and feature gate migrations.

Security & compliance
---------------------
- Policy and Scanner Integrations: automated SAST/linting for module stubs, manifest validations, and dependency vulnerability checks.
- Tenant Data Export/Import: per-tenant export/import tooling with retention/PII considerations.
- Permission Drivers Improvements: easy adapters for external identity providers (OIDC/SAML).

Performance & scale
-------------------
- Lazy Boot / Module Cache: cache module registry and lazily initialize non-critical boot tasks.
- Per-tenant Storage Backend: configurable storage isolation (S3 buckets, DB prefixes) per-tenant.

Migration & adoption
-------------------
- Importers: importers for `nwidart` modules, monolithic apps, and other module formats.
- Migration Guides & Automated Scripts: scripted, tested migration paths and checklists.

Next steps
----------
- Pick one quick win to implement and open a PR (docs or example module recommended).
