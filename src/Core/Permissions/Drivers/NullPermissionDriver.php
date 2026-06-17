<?php

namespace Modularity\Core\Permissions\Drivers;

use Modularity\Core\Permissions\Contracts\PermissionDriverInterface;

class NullPermissionDriver implements PermissionDriverInterface
{
    public function register(string $moduleSlug, array $permissions): void {}

    public function allForTenant(int $tenantId): array
    {
        return [];
    }

    public function userCan(object $user, string $permission): bool
    {
        return true;
    }
}
