<?php

use Modularity\Support\Facades\Module;
use Modularity\Support\Facades\Tenant;

/*
|--------------------------------------------------------------------------
| InteractsWithModules trait
|--------------------------------------------------------------------------
| Verifies the shipped testing helpers encapsulate the install/activate/re-boot
| pattern so hosts don't have to touch the loader. The widget fixture module is
| discovered via modules_path (see HttpTestCase).
*/

it('installs, activates and boots a module in one call', function () {
    $this->installAndActivateModule('widget', tenantId: 1);

    expect(Module::installed('widget'))->toBeTrue()
        ->and(Module::activeFor('widget', 1))->toBeTrue()
        ->and(Module::activeFor('widget', 2))->toBeFalse();

    // The provider booted, so its route is reachable for the active tenant.
    Tenant::set(1);
    $this->get('/widget')->assertOk()->assertSee('widget-ok');
});

it('gates the route for a tenant that has not activated the module', function () {
    $this->installAndActivateModule('widget', tenantId: 1);

    Tenant::set(2);
    $this->get('/widget')->assertNotFound();
});

it('asTenant sets and restores the tenant context', function () {
    Tenant::set(7);

    $inside = $this->asTenant(1, fn () => Tenant::id());

    expect($inside)->toBe(1)
        ->and(Tenant::id())->toBe(7); // restored afterwards
});
