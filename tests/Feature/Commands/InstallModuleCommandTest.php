<?php

use Modularity\Tests\Fixtures\ModuleFixture;

beforeEach(function () {
    $this->fixture = (new ModuleFixture('test-accounting'))->create();
});

afterEach(function () {
    $this->fixture->cleanup();
});

it('install command succeeds', function () {
    $this->artisan('module:install', [
        'slug'   => 'test-accounting',
        '--path' => $this->fixture->path(),
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('installed successfully');
});

it('install and activate with tenant', function () {
    $this->artisan('module:install', [
        'slug'     => 'test-accounting',
        '--path'   => $this->fixture->path(),
        '--tenant' => 5,
    ])->assertExitCode(0);

    $this->assertDatabaseHas('modularity_tenant_modules', [
        'module_slug' => 'test-accounting',
        'tenant_id'   => 5,
        'active'      => 1,
    ]);
});

it('install fails gracefully for missing module', function () {
    $this->artisan('module:install', ['slug' => 'does-not-exist'])
        ->assertExitCode(1);
});
