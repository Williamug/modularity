<?php

namespace Modularity\Marketplace\Contracts;

interface SubscriptionManagerInterface
{
    /**
     * Check whether the tenant has a valid subscription for the module.
     * Returns true if the tenant may activate the module.
     */
    public function check(int $tenantId, string $slug): bool;

    /**
     * Create a subscription for a tenant.
     */
    public function create(int $tenantId, string $slug, string $plan): void;

    /**
     * Cancel a tenant's subscription for a module.
     */
    public function cancel(int $tenantId, string $slug): void;
}
