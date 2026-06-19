<?php

namespace Modularity\Core\Lifecycle;

use Illuminate\Contracts\Events\Dispatcher;
use Modularity\Events\ModuleDeactivated;
use Modularity\Models\TenantModule;

class ModuleDeactivator
{
    public function __construct(
        private readonly Dispatcher $events,
    ) {}

    public function deactivate(string $slug, int $tenantId): void
    {
        TenantModule::forTenant($tenantId)
            ->forModule($slug)
            ->update([
                'active'         => false,
                'deactivated_at' => now(),
            ]);

        // Cache invalidation is driven by the ModuleDeactivated event below
        // (see CacheInvalidationListener).
        $this->events->dispatch(new ModuleDeactivated($slug, $tenantId));
    }

    /**
     * Deactivates a module for every tenant that has it active.
     * Uses a single bulk UPDATE instead of N individual queries.
     */
    public function deactivateAll(string $slug): void
    {
        $tenantIds = TenantModule::forModule($slug)
            ->active()
            ->pluck('tenant_id')
            ->all();

        if (empty($tenantIds)) {
            return;
        }

        TenantModule::forModule($slug)
            ->active()
            ->update([
                'active'         => false,
                'deactivated_at' => now(),
            ]);

        foreach ($tenantIds as $tenantId) {
            $this->events->dispatch(new ModuleDeactivated($slug, $tenantId));
        }
    }

    /**
     * Deactivates all modules for a given tenant.
     * Uses a single bulk UPDATE; cache invalidation is event-driven.
     * Wire this to your Tenant model's deleting event.
     */
    public function deactivateAllForTenant(int $tenantId): void
    {
        $slugs = TenantModule::forTenant($tenantId)
            ->active()
            ->pluck('module_slug')
            ->all();

        if (empty($slugs)) {
            return;
        }

        TenantModule::forTenant($tenantId)
            ->active()
            ->update([
                'active'         => false,
                'deactivated_at' => now(),
            ]);

        // Each ModuleDeactivated event triggers invalidation of this tenant's
        // cache via CacheInvalidationListener.
        foreach ($slugs as $slug) {
            $this->events->dispatch(new ModuleDeactivated($slug, $tenantId));
        }
    }
}
