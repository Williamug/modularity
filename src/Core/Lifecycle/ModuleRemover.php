<?php

namespace Modularity\Core\Lifecycle;

use Illuminate\Contracts\Events\Dispatcher;
use Modularity\Core\Module\Exceptions\ModuleNotInstalledException;
use Modularity\Core\Module\Exceptions\ModuleStillActiveException;
use Modularity\Core\Module\ModuleRegistry;
use Modularity\Events\ModuleRemoved;
use Modularity\Models\ModuleMigrationLog;
use Modularity\Models\TenantModule;

class ModuleRemover
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ModuleDeactivator $deactivator,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @param bool $force When true, deactivates all tenants before removing.
     */
    public function remove(string $slug, bool $force = false): void
    {
        $record = $this->registry->getInstalledRecord($slug);

        if (! $record) {
            throw ModuleNotInstalledException::slug($slug);
        }

        $activeTenantIds = TenantModule::forModule($slug)
            ->active()
            ->pluck('tenant_id')
            ->all();

        if (! empty($activeTenantIds)) {
            if (! $force) {
                throw ModuleStillActiveException::forTenants($slug, $activeTenantIds);
            }

            // Force: deactivate all tenants first
            $this->deactivator->deactivateAll($slug);
        }

        $record->delete();

        ModuleMigrationLog::forModule($slug)->delete();

        // Cache invalidation is handled by CacheInvalidationListener,
        // which reacts to the ModuleRemoved event below.
        $this->events->dispatch(new ModuleRemoved($slug));
    }
}
