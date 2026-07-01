<?php

namespace Modularity\Testing;

use Modularity\Core\Lifecycle\ModuleActivator;
use Modularity\Core\Lifecycle\ModuleInstaller;
use Modularity\Core\Module\ModuleRegistry;
use Modularity\Support\Facades\Tenant;

/**
 * Test helpers for host applications and module authors.
 *
 * The module loader boots once, at framework boot. In a test (with RefreshDatabase)
 * the database starts empty, so when the test later installs a module its provider —
 * and therefore its routes/views/navigation — would not be registered for the request
 * under test. These helpers install/activate a module AND re-boot the loader, so the
 * module behaves exactly as it would on a normal production request. Callers never need
 * to touch the loader or registry internals.
 *
 *     use Modularity\Testing\InteractsWithModules;
 *
 *     uses(Tests\TestCase::class, RefreshDatabase::class, InteractsWithModules::class);
 *
 *     beforeEach(fn () => $this->installAndActivateModule('library', tenantId: 1));
 */
trait InteractsWithModules
{
    /**
     * Install a module (runs its migrations, registers its permissions) and re-boot
     * the loader so its provider is registered for the current process.
     */
    protected function installModule(string $slug, ?string $path = null): void
    {
        app(ModuleInstaller::class)->install($slug, $path);

        $this->bootModules();
    }

    /**
     * Activate an already-installed module for a tenant and refresh that tenant's
     * cached active-module list so the change is visible immediately.
     */
    protected function activateModule(string $slug, int $tenantId): void
    {
        app(ModuleActivator::class)->activate($slug, $tenantId);

        app(ModuleRegistry::class)->invalidateTenant($tenantId);
    }

    /**
     * Install a module and activate it for a tenant in one step — the common
     * "make this module ready for tenant N" setup.
     */
    protected function installAndActivateModule(string $slug, int $tenantId, ?string $path = null): void
    {
        $this->installModule($slug, $path);
        $this->activateModule($slug, $tenantId);
    }

    /**
     * Re-run the module loader so the providers of every installed module are
     * registered. Safe to call repeatedly (registration is guarded).
     */
    protected function bootModules(): void
    {
        app(ModuleRegistry::class)->invalidateInstalled();

        app('modularity.loader')->boot();
    }

    /**
     * Run a callback with the tenant context set to $tenantId, then restore the
     * previous tenant. Returns whatever the callback returns.
     *
     * @template T
     * @param  callable():T  $callback
     * @return T
     */
    protected function asTenant(int $tenantId, callable $callback): mixed
    {
        $previous = Tenant::id();
        Tenant::set($tenantId);

        try {
            return $callback();
        } finally {
            $previous === null ? Tenant::forget() : Tenant::set($previous);
        }
    }
}
