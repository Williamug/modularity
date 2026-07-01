<?php

namespace Modularity\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modularity\Core\Module\ModuleRegistry;
use Modularity\Testing\InteractsWithModules;

/**
 * Base case for the HTTP integration suite. Unlike the unit/lifecycle tests, this
 * one boots the framework through real requests, so it needs an app key (the `web`
 * middleware group encrypts cookies), the real Gate permission driver (so BUG-4 is
 * observable), and a modules_path pointing at the on-disk fixture module — all set
 * BEFORE the app boots so the package's singletons resolve against them.
 */
abstract class HttpTestCase extends TestCase
{
    use InteractsWithModules;
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('modularity.permissions.driver', 'gate');
        $app['config']->set('modularity.modules_path', __DIR__.'/Fixtures/modules');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase rolls back DB rows between tests but leaves the registry's
        // in-memory cache populated; reset it so each test starts from a clean slate.
        $this->app->make(ModuleRegistry::class)->invalidateInstalled();
    }
}
