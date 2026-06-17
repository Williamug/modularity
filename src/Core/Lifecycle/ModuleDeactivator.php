<?php

namespace Modularity\Core\Lifecycle;

use Illuminate\Contracts\Events\Dispatcher;
use Modularity\Core\Module\ModuleRegistry;
use Modularity\Events\ModuleDeactivated;
use Modularity\Models\TenantModule;

class ModuleDeactivator
{
    public function __construct(
        private readonly ModuleRegistry $registry,
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

        $this->registry->invalidateTenant($tenantId);

        $this->events->dispatch(new ModuleDeactivated($slug, $tenantId));
    }

    /**
     * Deactivates a module for every tenant that has it active.
     * Used before module removal or on tenant deletion.
     */
    public function deactivateAll(string $slug): void
    {
        $tenantIds = TenantModule::forModule($slug)
            ->active()
            ->pluck('tenant_id')
            ->all();

        foreach ($tenantIds as $tenantId) {
            $this->deactivate($slug, $tenantId);
        }
    }

    /**
     * Deactivates all modules for a given tenant.
     * Wire this to your Tenant model's deleting event.
     */
    public function deactivateAllForTenant(int $tenantId): void
    {
        $slugs = TenantModule::forTenant($tenantId)
            ->active()
            ->pluck('module_slug')
            ->all();

        foreach ($slugs as $slug) {
            $this->deactivate($slug, $tenantId);
        }
    }
}
