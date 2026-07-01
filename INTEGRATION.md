# Integrating Modularity into your app

`architecture.md` explains how the package works internally. This guide is the
practical companion: the minimal wiring a host Laravel app needs to run modules
with session/auth-based multi-tenancy. With a default install, the package now
boots installed modules and gates them per tenant on its own — you only supply
two things it cannot know: **who the current tenant is** and **what each user is
allowed to do**.

> Targets Laravel 11+ (bootstrap/app.php middleware config). The same ideas apply
> to a Laravel 10 `Http/Kernel.php` — register the middleware there instead.

---

## 1. Install

```bash
composer require modularity/core
php artisan vendor:publish --tag=modularity-config
php artisan vendor:publish --tag=modularity-migrations
php artisan migrate
```

The default `CACHE_STORE` (database/file/redis/…) works out of the box — the
registry caches plain data, not Eloquent models.

---

## 2. Tell the package who the tenant is

Resolving the tenant is the **app's** job. The recommended approach is to derive
it from the authenticated user in your own middleware and hand it to the package:

```php
// app/Http/Middleware/SetCurrentTenant.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modularity\Support\Facades\Tenant;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->tenant_id !== null) {
            Tenant::set((int) $user->tenant_id);
        }

        return $next($request);
    }
}
```

Register it on the `web` group, and **before `SubstituteBindings`** so that
route-model binding is already tenant-scoped (a bound `{customer}` then resolves
only within the current tenant):

```php
// bootstrap/app.php
use App\Http\Middleware\SetCurrentTenant;
use Illuminate\Routing\Middleware\SubstituteBindings;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(append: [SetCurrentTenant::class]);

    $middleware->prependToPriorityList(
        before: SubstituteBindings::class,
        prepend: SetCurrentTenant::class,
    );
})
```

> Prefer the built-in `session` resolver instead? Add `'resolve.tenant'` to your
> route middleware and write the tenant id into the session at login
> (`session(['modularity_tenant_id' => $user->tenant_id])`). `Tenant::set()` from
> your own middleware is simpler and recommended.

---

## 3. Gate module routes per tenant

A module's routes are registered for **every installed module** so their URLs
always resolve. Whether the *current tenant* may reach them is enforced at
request time by the shipped `module.active` middleware (it 404s otherwise). The
scaffolded `routes/web.php` already uses it:

```php
Route::middleware(['web', 'auth', 'module.active:customer'])
    ->prefix('customers')->name('customers.')->group(function () {
        // ...
    });
```

You do **not** need to register module providers in `bootstrap/providers.php` or
write your own activeness middleware — the package does both.

---

## 4. Grant permissions to users

Each module declares its permissions in `module.json`. On boot the package
registers them as Gate abilities that **default to deny** — the machinery is
wired, but the package can't know which users hold which permissions. Grant them
from your own user/role model with a single `Gate::before`:

```php
// app/Providers/AppServiceProvider.php
use App\Models\User;
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::before(function (User $user, string $ability): ?bool {
        // Return true to allow, or null to fall through to the default (deny).
        return $user->hasModulePermission($ability) ? true : null;
    });
}
```

Now `can:customer.view` route middleware, `$user->can('customer.create')`, and
navigation `permission` filtering all reflect what each user may do. Using
`spatie/laravel-permission` instead? Set `modularity.permissions.driver` to
`spatie` and grant the same permission names through Spatie's roles.

---

## 5. Rendering the menu

`NavigationRegistry::forTenant()` returns only the menu items whose module is
active for the tenant **and** whose optional permission passes for the user:

```php
$items = Module::menu()->forTenant(
    tenantId: (int) auth()->user()->tenant_id,
    user: auth()->user(),
);
```

---

## 6. Fail-closed tenant scoping (optional, recommended for prod)

By default, querying a `BelongsToTenant` model with **no** tenant set returns
*all* tenants' rows (unscoped). To fail closed instead — throwing when isolation
would silently be disabled on a web request — enable strict mode:

```env
MODULARITY_TENANCY_STRICT=true
```

The console is exempt, so migrations, seeders and maintenance commands still run
unscoped.

---

## 7. Lifecycle commands

```bash
php artisan module:make-module Customer     # scaffold
php artisan module:install customer          # run migrations, register permissions
php artisan module:activate customer --tenant=1
php artisan module:list
```

> Module providers are intentionally **not** auto-booted on the CLI (booting every
> module on every artisan command was a historical memory problem), so
> `route:list` won't show module routes and module artisan commands must be
> registered deliberately. This does not affect HTTP requests, where modules boot
> normally.

---

## 8. Testing modules

`module:make-module` generates a starter test at `Modules/<Name>/tests/<Name>Test.php`.
Register a testsuite for it once in `phpunit.xml`:

```xml
<testsuite name="Modules">
    <directory>Modules/*/tests</directory>
</testsuite>
```

Use the shipped `Modularity\Testing\InteractsWithModules` trait — it install/activates a
module and re-boots the loader (needed because the loader boots once at app-boot, before a
test installs anything), so you never touch loader internals:

```php
use Modularity\Testing\InteractsWithModules;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class, InteractsWithModules::class);

beforeEach(fn () => $this->installAndActivateModule('customer', tenantId: 1));

it('isolates data per tenant', function () {
    $this->asTenant(1, fn () => Customer::create(['name' => 'Acme']));
    $this->asTenant(2, fn () => expect(Customer::count())->toBe(0));
});

it('gates routes by tenant activation', function () {
    $user = User::factory()->create(['tenant_id' => 1, 'permissions' => ['customer.view']]);
    $this->actingAs($user)->get(route('customers.index'))->assertOk();
});
```

Trait API: `installModule()`, `activateModule()`, `installAndActivateModule()`,
`bootModules()`, `asTenant()`. The demo's `tests/Feature/ModulesTest.php` is a fuller
reference (isolation, route-model-binding scope, permission gating, nav filtering,
cross-tenant FK rejection).
