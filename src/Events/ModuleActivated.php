<?php

namespace Modularity\Events;

use Modularity\Core\Events\Contracts\TenantAwareEvent;

class ModuleActivated implements TenantAwareEvent
{
    public function __construct(
        public readonly string $slug,
        public readonly int $tenantId,
    ) {}

    public function getTenantId(): int
    {
        return $this->tenantId;
    }
}
