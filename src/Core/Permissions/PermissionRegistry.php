<?php

namespace Modularity\Core\Permissions;

use Modularity\Core\Module\ModuleRegistry;
use Modularity\Core\Permissions\Contracts\PermissionDriverInterface;

class PermissionRegistry
{
    /** @var array<string, string[]> module slug => permission names */
    private array $modulePermissions = [];

    public function __construct(
        private readonly PermissionDriverInterface $driver,
        private readonly ModuleRegistry $registry,
    ) {}

    public function registerForModule(string $moduleSlug, array $permissions): void
    {
        if (empty($permissions)) {
            return;
        }

        $this->modulePermissions[$moduleSlug] = $permissions;
        $this->driver->register($moduleSlug, $permissions);
    }

    /**
     * Returns permission names scoped to modules active for the given tenant.
     * Prevents cross-tenant permission leakage.
     */
    public function allForTenant(int $tenantId): array
    {
        $activeSlugs = $this->registry->activeSlugsForTenant($tenantId);
        $permissions = [];

        foreach ($activeSlugs as $slug) {
            foreach ($this->modulePermissions[$slug] ?? [] as $perm) {
                $permissions[$perm] = true;
            }
        }

        return array_keys($permissions);
    }

    public function userCan(object $user, string $permission): bool
    {
        return $this->driver->userCan($user, $permission);
    }
}
