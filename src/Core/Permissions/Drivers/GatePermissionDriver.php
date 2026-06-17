<?php

namespace Modularity\Core\Permissions\Drivers;

use Illuminate\Support\Facades\Gate;
use Modularity\Core\Permissions\Contracts\PermissionDriverInterface;

class GatePermissionDriver implements PermissionDriverInterface
{
    private array $registered = [];

    public function register(string $moduleSlug, array $permissions): void
    {
        foreach ($permissions as $name) {
            if (isset($this->registered[$name])) {
                continue;
            }

            $this->registered[$name] = true;

            // Define a no-op gate ability; host app can override via Gate::before/after
            if (! Gate::has($name)) {
                Gate::define($name, fn () => false);
            }
        }
    }

    public function allForTenant(int $tenantId): array
    {
        return array_keys($this->registered);
    }

    public function userCan(object $user, string $permission): bool
    {
        if (! method_exists($user, 'can')) {
            return false;
        }

        return $user->can($permission);
    }
}
