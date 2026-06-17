<?php

namespace Modularity\Listeners;

use Modularity\Core\Module\ModuleRegistry;
use Modularity\Events\ModuleActivated;
use Modularity\Events\ModuleDeactivated;
use Modularity\Events\ModuleInstalled;
use Modularity\Events\ModuleRemoved;
use Modularity\Events\ModuleUpgraded;

class CacheInvalidationListener
{
    public function __construct(private readonly ModuleRegistry $registry) {}

    public function handleModuleInstalled(ModuleInstalled $event): void
    {
        $this->registry->invalidateInstalled();
    }

    public function handleModuleRemoved(ModuleRemoved $event): void
    {
        $this->registry->invalidateInstalled();
        $this->registry->invalidateAllTenants();
    }

    public function handleModuleUpgraded(ModuleUpgraded $event): void
    {
        $this->registry->invalidateInstalled();
        $this->registry->invalidateAllTenants();
    }

    public function handleModuleActivated(ModuleActivated $event): void
    {
        $this->registry->invalidateTenant($event->getTenantId());
    }

    public function handleModuleDeactivated(ModuleDeactivated $event): void
    {
        $this->registry->invalidateTenant($event->getTenantId());
    }
}
