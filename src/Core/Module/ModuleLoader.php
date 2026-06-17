<?php

namespace Modularity\Core\Module;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Modularity\Core\Module\Exceptions\CircularDependencyException;
use Modularity\Core\Module\Exceptions\InvalidManifestException;
use Modularity\Core\Tenancy\TenantContext;

class ModuleLoader
{
    public function __construct(
        private readonly Application $app,
        private readonly ModuleRegistry $registry,
        private readonly TenantContext $tenantContext,
    ) {}

    public function discover(): void
    {
        $this->discoverLocalModules();
        $this->discoverComposerModules();
    }

    public function boot(): void
    {
        $discovered = $this->registry->allDiscovered();

        if (empty($discovered)) {
            return;
        }

        $installedManifests = array_filter(
            $discovered,
            fn (ManifestDTO $m) => $this->registry->isInstalled($m->slug)
        );

        if (empty($installedManifests)) {
            return;
        }

        try {
            $sorted = (new DependencyGraph(array_values($installedManifests)))->resolve();
        } catch (CircularDependencyException $e) {
            Log::error('[Modularity] '.$e->getMessage());

            return;
        }

        $tenantId = $this->tenantContext->id();

        foreach ($sorted as $manifest) {
            $this->bootModule($manifest, $tenantId);
        }
    }

    private function bootModule(ManifestDTO $manifest, ?int $tenantId): void
    {
        $installedRecord = $this->registry->getInstalledRecord($manifest->slug);

        if ($installedRecord?->isErrored()) {
            return;
        }

        $isActive = $tenantId !== null
            ? $this->registry->activeFor($manifest->slug, $tenantId)
            : false;

        // In CLI context with no tenant, we still register the provider so
        // commands and migrations are available, but the tenant gate inside
        // ModuleServiceProvider::boot() will skip UI/route registration.
        $shouldBoot = $isActive || $this->app->runningInConsole();

        if (! $shouldBoot) {
            return;
        }

        foreach ($manifest->providers as $providerClass) {
            if (! class_exists($providerClass)) {
                Log::warning("[Modularity] Provider [{$providerClass}] for module [{$manifest->slug}] does not exist.");

                continue;
            }

            if ($this->app->providerIsLoaded($providerClass)) {
                continue;
            }

            $this->app->register($providerClass);
        }
    }

    private function discoverLocalModules(): void
    {
        $modulesPath = config('modularity.modules_path', base_path('Modules'));

        if (! is_dir($modulesPath)) {
            return;
        }

        foreach (scandir($modulesPath) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $moduleDir = $modulesPath.DIRECTORY_SEPARATOR.$entry;

            if (! is_dir($moduleDir) || ! file_exists($moduleDir.'/module.json')) {
                continue;
            }

            $this->parseAndRegister($moduleDir);
        }
    }

    private function discoverComposerModules(): void
    {
        $installedPath = base_path('vendor/composer/installed.json');

        if (! file_exists($installedPath)) {
            return;
        }

        $installed = json_decode(file_get_contents($installedPath), true);
        $packages  = $installed['packages'] ?? $installed;

        if (! is_array($packages)) {
            return;
        }

        foreach ($packages as $package) {
            $isModule = ($package['extra']['modularity']['module'] ?? false) === true;

            if (! $isModule) {
                continue;
            }

            // Find the package installation path
            $name      = $package['name'] ?? '';
            $vendorDir = base_path('vendor/'.$name);

            if (is_dir($vendorDir) && file_exists($vendorDir.'/module.json')) {
                $this->parseAndRegister($vendorDir);
            }
        }
    }

    private function parseAndRegister(string $path): void
    {
        try {
            $manifest = ModuleManifest::parse($path);
            $this->registry->registerDiscovered($manifest);
        } catch (InvalidManifestException $e) {
            Log::warning('[Modularity] '.$e->getMessage());
        }
    }
}
