<?php

namespace Modularity\Core\Permissions\Contracts;

interface PermissionDriverInterface
{
    /**
     * Idempotently register permissions for a module.
     *
     * @param string[] $permissions
     */
    public function register(string $moduleSlug, array $permissions): void;

    /**
     * Returns all permission names across all active modules for the given tenant.
     *
     * @return string[]
     */
    public function allForTenant(int $tenantId): array;

    public function userCan(object $user, string $permission): bool;
}
