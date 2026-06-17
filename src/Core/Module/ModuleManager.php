<?php

namespace Modularity\Core\Module;

use Modularity\Core\Navigation\NavigationRegistry;
use Modularity\Core\Permissions\PermissionRegistry;
use Modularity\Core\Tenancy\TenantContext;

class ModuleManager
{
    /** @var array<string, array> */
    private array $settingsCache = [];

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly TenantContext $tenantContext,
        private readonly NavigationRegistry $navigation,
        private readonly PermissionRegistry $permissions,
    ) {}

    public function active(string $slug): bool
    {
        $tenantId = $this->tenantContext->id();

        if ($tenantId === null) {
            return false;
        }

        return $this->registry->activeFor($slug, $tenantId);
    }

    public function activeFor(string $slug, int $tenantId): bool
    {
        return $this->registry->activeFor($slug, $tenantId);
    }

    public function installed(string $slug): bool
    {
        return $this->registry->isInstalled($slug);
    }

    public function discovered(string $slug): bool
    {
        return $this->registry->isDiscovered($slug);
    }

    public function menu(): NavigationRegistry
    {
        return $this->navigation;
    }

    public function permissions(): PermissionRegistry
    {
        return $this->permissions;
    }

    public function config(string $slug, string $key, mixed $default = null): mixed
    {
        $tenantId = $this->tenantContext->id();

        if ($tenantId === null) {
            return $default;
        }

        $cacheKey = "{$tenantId}.{$slug}";

        if (! isset($this->settingsCache[$cacheKey])) {
            $record = \Modularity\Models\TenantModule::forTenant($tenantId)
                ->forModule($slug)
                ->first();

            $this->settingsCache[$cacheKey] = $record?->settings ?? [];
        }

        return data_get($this->settingsCache[$cacheKey], $key, $default);
    }

    public function registry(): ModuleRegistry
    {
        return $this->registry;
    }
}
