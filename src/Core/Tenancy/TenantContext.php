<?php

namespace Modularity\Core\Tenancy;

use Modularity\Core\Tenancy\Exceptions\TenantNotResolvedException;

class TenantContext
{
    private ?int $tenantId = null;

    public function set(int $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function id(): ?int
    {
        return $this->tenantId;
    }

    public function isSet(): bool
    {
        return $this->tenantId !== null;
    }

    public function forget(): void
    {
        $this->tenantId = null;
    }

    public function assertSet(): int
    {
        if ($this->tenantId === null) {
            throw new TenantNotResolvedException();
        }

        return $this->tenantId;
    }
}
