<?php

namespace Modularity\Core\Events\Contracts;

interface TenantAwareEvent
{
    public function getTenantId(): int;
}
