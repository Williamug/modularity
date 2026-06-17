<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Modularity\Core\Lifecycle\ModuleInstaller;
use Modularity\Core\Module\Exceptions\DependencyNotInstalledException;
use Modularity\Core\Module\Exceptions\ModuleNotFoundException;
use Modularity\Events\ModuleInstalled;
use Modularity\Models\InstalledModule;
use Modularity\Tests\Fixtures\ModuleFixture;

beforeEach(function () {
    $this->fixture = (new ModuleFixture('test-library'))->create();
});

afterEach(function () {
    $this->fixture->cleanup();
});

it('installs module and creates db record', function () {
    $record = app(ModuleInstaller::class)->install('test-library', $this->fixture->path());

    expect($record)->toBeInstanceOf(InstalledModule::class);
    expect($record->slug)->toBe('test-library');
    expect($record->status)->toBe('installed');
    $this->assertDatabaseHas('modularity_installed_modules', ['slug' => 'test-library']);
});

it('install is idempotent', function () {
    $installer = app(ModuleInstaller::class);
    $first     = $installer->install('test-library', $this->fixture->path());
    $second    = $installer->install('test-library', $this->fixture->path());

    expect($first->id)->toBe($second->id);
    $this->assertDatabaseCount('modularity_installed_modules', 1);
});

it('installs with migration and logs it', function () {
    $fixture = (new ModuleFixture('test-blog'))
        ->create()
        ->withMigration('test_blog_posts');

    app(ModuleInstaller::class)->install('test-blog', $fixture->path());

    $this->assertDatabaseHas('modularity_migration_log', ['module_slug' => 'test-blog']);
    expect(Schema::hasTable('test_blog_posts'))->toBeTrue();

    $fixture->cleanup();
});

it('throws when module not found in registry', function () {
    expect(fn () => app(ModuleInstaller::class)->install('nonexistent-module'))
        ->toThrow(ModuleNotFoundException::class);
});

it('throws when dependency not installed', function () {
    $fixture = (new ModuleFixture('module-b', '1.0.0', [['slug' => 'module-a']]))->create();

    expect(fn () => app(ModuleInstaller::class)->install('module-b', $fixture->path()))
        ->toThrow(DependencyNotInstalledException::class, 'module-a');

    $fixture->cleanup();
});

it('fires module installed event', function () {
    $fired = false;

    Event::listen(ModuleInstalled::class, function () use (&$fired) {
        $fired = true;
    });

    app(ModuleInstaller::class)->install('test-library', $this->fixture->path());

    expect($fired)->toBeTrue();
});
