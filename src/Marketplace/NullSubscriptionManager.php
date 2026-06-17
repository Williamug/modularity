<?php

namespace Modularity\Marketplace;

use Modularity\Marketplace\Contracts\SubscriptionManagerInterface;

class NullSubscriptionManager implements SubscriptionManagerInterface
{
    public function check(int $tenantId, string $slug): bool
    {
        return true;
    }

    public function create(int $tenantId, string $slug, string $plan): void {}

    public function cancel(int $tenantId, string $slug): void {}
}
