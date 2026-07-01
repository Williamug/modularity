<?php

namespace Modularity\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modularity\Core\Module\ModuleRegistry;

abstract class FeatureTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The ModuleRegistry singleton caches installed/active state in memory.
        // RefreshDatabase rolls back DB records between tests, but the in-memory
        // cache is not automatically cleared. Reset it so each test starts clean.
        $this->app->make(ModuleRegistry::class)->invalidateInstalled();
    }
}
