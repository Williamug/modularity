<?php

namespace Modularity\Core\Module;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
        $this->cacheStore()->forget('modularity.registry.installed');
    }

    public function invalidateTenant(int $tenantId): void
    {
        unset($this->tenantActive[$tenantId]);
        $this->cacheStore()->forget("modularity.registry.tenant.{$tenantId}");
    }

    public function invalidateAllTenants(): void
    {
        $this->tenantActive = [];

        $store          = $this->cacheStore();
        $knownTenantIds = (array) ($store->get('modularity.registry.tenant_ids') ?? []);

        foreach ($knownTenantIds as $id) {
            $store->forget("modularity.registry.tenant.{$id}");
        }

        $store->forget('modularity.registry.tenant_ids');
    }

    // -----------------------------------------------------------------------

    private function cacheStore(): \Illuminate\Contracts\Cache\Repository
    {
        return Cache::store(config('modularity.cache.store'));
    }

    private function getInstalled(): array
    {
        if ($this->installed !== null) {
            return $this->installed;
        }

        $cacheEnabled = config('modularity.cache.enabled', true);
        $cacheKey     = 'modularity.registry.installed';
        $cacheTtl     = config('modularity.cache.ttl', 3600);

        if ($cacheEnabled) {
            $cached = $this->cacheStore()->get($cacheKey);

            if ($cached !== null) {
                // Cached as plain attribute arrays (never live Eloquent models, which
                // come back as __PHP_Incomplete_Class on any serializing store). Rehydrate.
                $this->installed = $this->hydrateInstalled($cached);

                return $this->installed;
            }
        }

        try {
            $records = InstalledModule::all()->keyBy('slug')->all();
        } catch (\Throwable $e) {
            // Database is unavailable (e.g. migrations not run yet, connection failure).
            // Return empty and let the caller retry on the next request/command.
            Log::debug('[Modularity] Installed modules unavailable: '.$e->getMessage());

            return [];
        }

        $this->installed = $records;

        if ($cacheEnabled) {
            // Store primitives only. Caching the Eloquent models directly breaks on
            // every serializing cache store (database/file/redis/memcached): the next
            // process reads them back as __PHP_Incomplete_Class and getInstalledRecord()
            // throws a TypeError during boot.
            $this->cacheStore()->put($cacheKey, $this->dehydrateInstalled($records), $cacheTtl);
        }

        return $this->installed;
    }

    /**
     * @param  array<string, InstalledModule>  $records
     * @return array<string, array<string, mixed>>
     */
    private function dehydrateInstalled(array $records): array
    {
        return array_map(static fn (InstalledModule $m): array => $m->getAttributes(), $records);
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<string, InstalledModule>
     */
    private function hydrateInstalled(array $rows): array
    {
        $models = [];

        foreach ($rows as $slug => $attributes) {
            // newFromBuilder() marks the model as existing (loaded from storage), so it
            // behaves exactly like a record read straight from the database.
            $models[$slug] = (new InstalledModule())->newFromBuilder($attributes);
        }

        return $models;
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
            $cached = $this->cacheStore()->get($cacheKey);

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
            $store = $this->cacheStore();
            $store->put($cacheKey, $slugs, $cacheTtl);

            // Track this tenant ID so invalidateAllTenants() can clear its cache entry.
            $knownIds = (array) ($store->get('modularity.registry.tenant_ids') ?? []);
            if (! in_array($tenantId, $knownIds, true)) {
                $knownIds[] = $tenantId;
                $store->put('modularity.registry.tenant_ids', $knownIds, $cacheTtl);
            }
        }

        return $this->tenantActive[$tenantId];
    }
}
