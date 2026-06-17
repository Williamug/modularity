<?php

use Illuminate\Support\Facades\Event;
use Modularity\Core\Lifecycle\ModuleActivator;
use Modularity\Core\Lifecycle\ModuleDeactivator;
use Modularity\Core\Lifecycle\ModuleInstaller;
use Modularity\Events\ModuleDeactivated;
use Modularity\Models\TenantModule;
use Modularity\Tests\Fixtures\ModuleFixture;

beforeEach(function () {
    $this->fixture = (new ModuleFixture('test-pos'))->create();

    app(ModuleInstaller::class)->install('test-pos', $this->fixture->path());
    app(ModuleActivator::class)->activate('test-pos', 1);
});

afterEach(function () {
    $this->fixture->cleanup();
});

it('deactivate sets active false', function () {
    app(ModuleDeactivator::class)->deactivate('test-pos', 1);

    $this->assertDatabaseHas('modularity_tenant_modules', [
        'module_slug' => 'test-pos',
        'tenant_id'   => 1,
        'active'      => 0,
    ]);
});

it('deactivate does not delete record', function () {
    app(ModuleDeactivator::class)->deactivate('test-pos', 1);

    $this->assertDatabaseCount('modularity_tenant_modules', 1);
});

it('deactivate does not delete installed record', function () {
    app(ModuleDeactivator::class)->deactivate('test-pos', 1);

    $this->assertDatabaseHas('modularity_installed_modules', ['slug' => 'test-pos']);
});

it('deactivate all deactivates every tenant', function () {
    app(ModuleActivator::class)->activate('test-pos', 2);
    app(ModuleActivator::class)->activate('test-pos', 3);

    app(ModuleDeactivator::class)->deactivateAll('test-pos');

    $activeCount = TenantModule::forModule('test-pos')->active()->count();
    expect($activeCount)->toBe(0);
});

it('fires module deactivated event', function () {
    $fired = false;

    Event::listen(ModuleDeactivated::class, function ($event) use (&$fired) {
        $fired = true;
        expect($event->slug)->toBe('test-pos');
        expect($event->tenantId)->toBe(1);
    });

    app(ModuleDeactivator::class)->deactivate('test-pos', 1);

    expect($fired)->toBeTrue();
});
