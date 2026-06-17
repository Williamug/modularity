<?php

namespace Modularity\Core\Permissions\Drivers;

use Modularity\Core\Permissions\Contracts\PermissionDriverInterface;

class SpatiePermissionDriver implements PermissionDriverInterface
{
    public function register(string $moduleSlug, array $permissions): void
    {
        if (! class_exists(\Spatie\Permission\Models\Permission::class)) {
            return;
        }

        $guard = config('auth.defaults.guard', 'web');

        foreach ($permissions as $name) {
            \Spatie\Permission\Models\Permission::firstOrCreate([
                'name'       => $name,
                'guard_name' => $guard,
            ]);
        }
    }

    public function allForTenant(int $tenantId): array
    {
        if (! class_exists(\Spatie\Permission\Models\Permission::class)) {
            return [];
        }

        return \Spatie\Permission\Models\Permission::pluck('name')->all();
    }

    public function userCan(object $user, string $permission): bool
    {
        if (! method_exists($user, 'can')) {
            return false;
        }

        return $user->can($permission);
    }
}
