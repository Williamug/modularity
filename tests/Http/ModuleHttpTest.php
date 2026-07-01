<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Modularity\Core\Lifecycle\ModuleActivator;
use Modularity\Core\Lifecycle\ModuleInstaller;
use Modularity\Core\Module\ModuleRegistry;
use Modularity\Http\Middleware\ResolveTenantMiddleware;
use Modularity\Models\InstalledModule;
use Modularity\Support\Facades\Tenant;

/*
|--------------------------------------------------------------------------
| HTTP integration harness
|--------------------------------------------------------------------------
| Boots the framework through real HTTP requests with an on-disk fixture
| module (tests/Fixtures/modules/widget, discovered via modules_path set in
| HttpTestCase). This is the integration layer the unit/lifecycle tests never
| touch — the gap where BUG-1..4 hid.
*/

function widgetPath(): string
{
    return dirname(__DIR__).'/Fixtures/modules/widget';
}

/**
 * Installs + activates the (already discovered) widget fixture for a tenant, then
 * boots the loader so its provider — and therefore its routes — register. This is
 * exactly what a real process does at boot once a module is installed.
 */
function bootWidgetFor(int $tenantId): void
{
    $registry = app(ModuleRegistry::class);

    app(ModuleInstaller::class)->install('widget', widgetPath());
    app(ModuleActivator::class)->activate('widget', $tenantId);

    $registry->invalidateInstalled();
    $registry->invalidateTenant($tenantId);

    app('modularity.loader')->boot();
}

it('resolves the resolve.tenant middleware from the container (BUG-2)', function () {
    // Before the alias fix this threw "Unresolvable dependency ... array $resolvers".
    expect(app(ResolveTenantMiddleware::class))->toBeInstanceOf(ResolveTenantMiddleware::class);
});

it('registers an installed module\'s routes at boot, independent of tenant (BUG-3)', function () {
    bootWidgetFor(1);

    // The route exists even though no tenant was active when providers first booted.
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r) => $r->uri() === 'widget');

    expect($route)->not->toBeNull();
});

it('serves a module route to a tenant that has it active (BUG-3)', function () {
    bootWidgetFor(1);

    Tenant::set(1);

    $this->get('/widget')
        ->assertOk()
        ->assertSee('widget-ok');
});

it('404s a module route for a tenant that does not have it active (module.active)', function () {
    bootWidgetFor(1);

    // Tenant 2 never activated the widget module.
    Tenant::set(2);

    $this->get('/widget')->assertNotFound();
});

it('registers a module\'s permissions as Gate abilities at boot (BUG-4)', function () {
    expect(Gate::has('widget.view'))->toBeFalse();

    bootWidgetFor(1);

    expect(Gate::has('widget.view'))->toBeTrue();
});

it('caches installed modules as plain arrays, not Eloquent models (BUG-1)', function () {
    config([
        'modularity.cache.enabled' => true,
        'modularity.cache.store'   => 'array',
    ]);

    app(ModuleInstaller::class)->install('widget', widgetPath());

    $registry = app(ModuleRegistry::class);
    $registry->invalidateInstalled();   // clear in-memory + cache, then repopulate
    $registry->allInstalled();          // triggers DB read + cache write

    $cached = Cache::store('array')->get('modularity.registry.installed');

    // The cached payload must be primitives only. Caching live models is what
    // produced __PHP_Incomplete_Class (and a boot-time TypeError) on serializing stores.
    expect($cached)->toBeArray();
    foreach ($cached as $row) {
        expect($row)->toBeArray()
            ->and($row)->not->toBeInstanceOf(InstalledModule::class);
    }

    // A brand-new registry must rehydrate the cached primitives into real models.
    $fresh  = new ModuleRegistry();
    $record = $fresh->getInstalledRecord('widget');

    expect($record)->toBeInstanceOf(InstalledModule::class)
        ->and($record->slug)->toBe('widget')
        ->and($record->exists)->toBeTrue();
});
