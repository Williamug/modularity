<?php

namespace Modularity\Core\Module;

use Illuminate\Support\Facades\Cache;
use Modularity\Models\InstalledModule;
use Modularity\Models\TenantModule;

class ModuleRegistry
{
    /** @var array<string, ManifestDTO> */
    private array $discovered = [];

    /** @var array<string, InstalledModule>|null */
    private ?array $installed = null;

    /** @var array<int, string[]> */
    private array $tenantActive = [];

    public function registerDiscovered(ManifestDTO $manifest): void
    {
        $this->discovered[$manifest->slug] = $manifest;
    }

    public function allDiscovered(): array
    {
        return $this->discovered;
    }

    public function getManifest(string $slug): ?ManifestDTO
    {
        return $this->discovered[$slug] ?? null;
    }

    public function isDiscovered(string $slug): bool
    {
        return isset($this->discovered[$slug]);
    }

    public function isInstalled(string $slug): bool
    {
        return isset($this->getInstalled()[$slug]);
    }

    public function getInstalledRecord(string $slug): ?InstalledModule
    {
        return $this->getInstalled()[$slug] ?? null;
    }

    public function allInstalled(): array
    {
        return $this->getInstalled();
    }

    public function activeFor(string $slug, int $tenantId): bool
    {
        return in_array($slug, $this->getTenantActive($tenantId), true);
    }

    public function activeSlugsForTenant(int $tenantId): array
    {
        return $this->getTenantActive($tenantId);
    }

    public function invalidateInstalled(): void
    {
        $this->installed = null;
        Cache::forget('modularity.registry.installed');
    }

    public function invalidateTenant(int $tenantId): void
    {
        unset($this->tenantActive[$tenantId]);
        Cache::forget("modularity.registry.tenant.{$tenantId}");
    }

    public function invalidateAllTenants(): void
    {
        $this->tenantActive = [];
        // No pattern-delete available in all drivers; individual tenants are
        // invalidated as needed. The in-memory cache is cleared here.
    }

    // -----------------------------------------------------------------------

    private function getInstalled(): array
    {
        if ($this->installed !== null) {
            return $this->installed;
        }

        $cacheEnabled = config('modularity.cache.enabled', true);
        $cacheKey     = 'modularity.registry.installed';
        $cacheTtl     = config('modularity.cache.ttl', 3600);

        if ($cacheEnabled) {
            $cached = Cache::store(config('modularity.cache.store'))->get($cacheKey);

            if ($cached !== null) {
                $this->installed = $cached;

                return $this->installed;
            }
        }

        $records = InstalledModule::all()->keyBy('slug')->all();
        $this->installed = $records;

        if ($cacheEnabled) {
            Cache::store(config('modularity.cache.store'))->put($cacheKey, $records, $cacheTtl);
        }

        return $this->installed;
    }

    private function getTenantActive(int $tenantId): array
    {
        if (isset($this->tenantActive[$tenantId])) {
            return $this->tenantActive[$tenantId];
        }

        $cacheEnabled = config('modularity.cache.enabled', true);
        $cacheKey     = "modularity.registry.tenant.{$tenantId}";
        $cacheTtl     = config('modularity.cache.ttl', 3600);

        if ($cacheEnabled) {
            $cached = Cache::store(config('modularity.cache.store'))->get($cacheKey);

            if ($cached !== null) {
                $this->tenantActive[$tenantId] = $cached;

                return $this->tenantActive[$tenantId];
            }
        }

        $slugs = TenantModule::forTenant($tenantId)
            ->active()
            ->pluck('module_slug')
            ->all();

        $this->tenantActive[$tenantId] = $slugs;

        if ($cacheEnabled) {
            Cache::store(config('modularity.cache.store'))->put($cacheKey, $slugs, $cacheTtl);
        }

        return $this->tenantActive[$tenantId];
    }
}
