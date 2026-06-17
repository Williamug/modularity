<?php

namespace Modularity\Core\Permissions;

use Modularity\Core\Permissions\Contracts\PermissionDriverInterface;

class PermissionRegistry
{
    public function __construct(private readonly PermissionDriverInterface $driver) {}

    public function registerForModule(string $moduleSlug, array $permissions): void
    {
        if (empty($permissions)) {
            return;
        }

        $this->driver->register($moduleSlug, $permissions);
    }

    public function allForTenant(int $tenantId): array
    {
        return $this->driver->allForTenant($tenantId);
    }

    public function userCan(object $user, string $permission): bool
    {
        return $this->driver->userCan($user, $permission);
    }
}
