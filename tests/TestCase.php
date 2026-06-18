<?php

namespace Modularity\Tests;

use Modularity\ModularityServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [ModularityServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Module' => \Modularity\Support\Facades\Module::class,
            'Tenant' => \Modularity\Support\Facades\Tenant::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $app['config']->set('modularity.cache.enabled', false);
        $app['config']->set('modularity.cache.store', 'array');
        $app['config']->set('modularity.permissions.driver', 'null');

        // Ensure cache.stores.array exists so Cache::store('array') resolves cleanly.
        $app['config']->set('cache.stores.array', ['driver' => 'array', 'serialize' => false]);
    }

    protected function defineDatabaseMigrations(): void
    {
        // ModularityServiceProvider::boot() calls loadMigrationsFrom() unconditionally,
        // so the package migrations are already registered when Testbench runs artisan migrate.
        // No second registration needed here.
    }
}
