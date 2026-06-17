<?php

namespace Modularity\Core\Navigation;

use Illuminate\Support\Collection;

class NavigationRegistry
{
    /** @var MenuItem[] */
    private array $items = [];

    public function add(array|MenuItem $item): void
    {
        if (is_array($item)) {
            $item = MenuItem::fromArray($item);
        }

        $this->items[] = $item;
    }

    /**
     * Returns menu items for the given tenant, filtered by:
     * - Module must be active for the tenant
     * - Permission must pass (if set)
     *
     * Items are sorted by order ascending.
     */
    public function forTenant(int $tenantId, ?object $user = null): Collection
    {
        $manager = app(\Modularity\Core\Module\ModuleManager::class);

        return collect($this->items)
            ->filter(function (MenuItem $item) use ($manager, $tenantId, $user) {
                if (! $manager->activeFor($item->module, $tenantId)) {
                    return false;
                }

                if ($item->permission && $user !== null) {
                    if (method_exists($user, 'can') && ! $user->can($item->permission)) {
                        return false;
                    }
                }

                return true;
            })
            ->sortBy('order')
            ->values();
    }

    /**
     * Returns all registered items grouped by their group label.
     */
    public function forTenantGrouped(int $tenantId, ?object $user = null): Collection
    {
        return $this->forTenant($tenantId, $user)
            ->groupBy('group');
    }

    public function all(): array
    {
        return $this->items;
    }

    public function flush(): void
    {
        $this->items = [];
    }
}
