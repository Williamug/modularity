<?php

use Illuminate\Support\Facades\Event;
use Modularity\Core\Lifecycle\ModuleActivator;
use Modularity\Core\Lifecycle\ModuleInstaller;
use Modularity\Core\Module\Exceptions\ModuleNotInstalledException;
use Modularity\Events\ModuleActivated;
use Modularity\Tests\Fixtures\ModuleFixture;

beforeEach(function () {
    $this->fixture = (new ModuleFixture('test-crm'))->create();
});

afterEach(function () {
    $this->fixture->cleanup();
});

it('activates module for tenant', function () {
    app(ModuleInstaller::class)->install('test-crm', $this->fixture->path());
    $record = app(ModuleActivator::class)->activate('test-crm', 1);

    expect($record->active)->toBeTrue();
    expect($record->tenant_id)->toBe(1);
    $this->assertDatabaseHas('modularity_tenant_modules', [
        'module_slug' => 'test-crm',
        'tenant_id'   => 1,
        'active'      => 1,
    ]);
});

it('activate is idempotent', function () {
    app(ModuleInstaller::class)->install('test-crm', $this->fixture->path());
    app(ModuleActivator::class)->activate('test-crm', 1);
    app(ModuleActivator::class)->activate('test-crm', 1);

    $this->assertDatabaseCount('modularity_tenant_modules', 1);
});

it('different tenants are independent', function () {
    app(ModuleInstaller::class)->install('test-crm', $this->fixture->path());
    app(ModuleActivator::class)->activate('test-crm', 1);
    app(ModuleActivator::class)->activate('test-crm', 2);

    $this->assertDatabaseCount('modularity_tenant_modules', 2);
    $this->assertDatabaseHas('modularity_tenant_modules', ['tenant_id' => 1, 'active' => 1]);
    $this->assertDatabaseHas('modularity_tenant_modules', ['tenant_id' => 2, 'active' => 1]);
});

it('throws when module not installed', function () {
    expect(fn () => app(ModuleActivator::class)->activate('not-installed', 1))
        ->toThrow(ModuleNotInstalledException::class);
});

it('fires module activated event', function () {
    $fired = false;

    Event::listen(ModuleActivated::class, function ($event) use (&$fired) {
        $fired = true;
        expect($event->slug)->toBe('test-crm');
        expect($event->tenantId)->toBe(1);
    });

    app(ModuleInstaller::class)->install('test-crm', $this->fixture->path());
    app(ModuleActivator::class)->activate('test-crm', 1);

    expect($fired)->toBeTrue();
});
