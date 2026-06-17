<?php

use Illuminate\Support\Facades\Event;
use Modularity\Core\Lifecycle\ModuleActivator;
use Modularity\Core\Lifecycle\ModuleInstaller;
use Modularity\Core\Lifecycle\ModuleRemover;
use Modularity\Core\Module\Exceptions\ModuleNotInstalledException;
use Modularity\Core\Module\Exceptions\ModuleStillActiveException;
use Modularity\Events\ModuleRemoved;
use Modularity\Tests\Fixtures\ModuleFixture;

beforeEach(function () {
    $this->fixture = (new ModuleFixture('test-hrm'))->create();
});

afterEach(function () {
    $this->fixture->cleanup();
});

it('removes installed module with no active tenants', function () {
    app(ModuleInstaller::class)->install('test-hrm', $this->fixture->path());
    app(ModuleRemover::class)->remove('test-hrm');

    $this->assertDatabaseMissing('modularity_installed_modules', ['slug' => 'test-hrm']);
});

it('throws when tenants still active', function () {
    app(ModuleInstaller::class)->install('test-hrm', $this->fixture->path());
    app(ModuleActivator::class)->activate('test-hrm', 1);

    expect(fn () => app(ModuleRemover::class)->remove('test-hrm'))
        ->toThrow(ModuleStillActiveException::class);
});

it('force remove deactivates all tenants first', function () {
    app(ModuleInstaller::class)->install('test-hrm', $this->fixture->path());
    app(ModuleActivator::class)->activate('test-hrm', 1);
    app(ModuleActivator::class)->activate('test-hrm', 2);

    app(ModuleRemover::class)->remove('test-hrm', force: true);

    $this->assertDatabaseMissing('modularity_installed_modules', ['slug' => 'test-hrm']);
    $this->assertDatabaseHas('modularity_tenant_modules', ['module_slug' => 'test-hrm', 'active' => 0]);
});

it('throws when module not installed', function () {
    expect(fn () => app(ModuleRemover::class)->remove('nonexistent'))
        ->toThrow(ModuleNotInstalledException::class);
});

it('fires module removed event', function () {
    $fired = false;

    Event::listen(ModuleRemoved::class, function ($event) use (&$fired) {
        $fired = true;
        expect($event->slug)->toBe('test-hrm');
    });

    app(ModuleInstaller::class)->install('test-hrm', $this->fixture->path());
    app(ModuleRemover::class)->remove('test-hrm');

    expect($fired)->toBeTrue();
});
