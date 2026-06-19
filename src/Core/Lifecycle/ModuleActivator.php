<?php

namespace Modularity\Core\Lifecycle;

use Illuminate\Contracts\Events\Dispatcher;
use Modularity\Core\Module\Exceptions\ModuleNotInstalledException;
use Modularity\Core\Module\ModuleRegistry;
use Modularity\Events\ModuleActivated;
use Modularity\Marketplace\Contracts\SubscriptionManagerInterface;
use Modularity\Marketplace\Exceptions\SubscriptionRequiredException;
use Modularity\Models\TenantModule;

class ModuleActivator
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly SubscriptionManagerInterface $subscriptions,
        private readonly Dispatcher $events,
    ) {}

    public function activate(string $slug, int $tenantId): TenantModule
    {
        if (! $this->registry->isInstalled($slug)) {
            throw ModuleNotInstalledException::slug($slug);
        }

        if (! $this->subscriptions->check($tenantId, $slug)) {
            throw SubscriptionRequiredException::forModule($slug, $tenantId);
        }

        $record = TenantModule::updateOrCreate(
            ['tenant_id' => $tenantId, 'module_slug' => $slug],
            ['active' => true, 'activated_at' => now(), 'deactivated_at' => null]
        );

        // CacheInvalidationListener invalidates this tenant's cache in
        // response to the ModuleActivated event.
        $this->events->dispatch(new ModuleActivated($slug, $tenantId));

        return $record;
    }
}
