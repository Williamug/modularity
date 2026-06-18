<?php

namespace Modularity\Core\Module;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Modularity\Core\Module\Exceptions\CircularDependencyException;
use Modularity\Core\Module\Exceptions\InvalidManifestException;
use Modularity\Core\Tenancy\TenantContext;

class ModuleLoader
{
    /** @var array<int, array<string, mixed>>|null Parsed modularity packages from installed.json */
    private ?array $composerModulePackages = null;

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

        // Always filter to installed modules only. getInstalled() has a try/catch
        // that gracefully returns [] when the database is unavailable (e.g. before
        // migrations have run), so this is safe in all contexts including CLI.
        $candidates = array_values(array_filter(
            $discovered,
            fn (ManifestDTO $m) => $this->registry->isInstalled($m->slug)
        ));

        if (empty($candidates)) {
            return;
        }

        try {
            $sorted = (new DependencyGraph($candidates))->resolve();
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

        // Only boot a module's providers when it is active for the current tenant.
        // Do NOT auto-boot in CLI (runningInConsole). Booting all installed modules
        // for every artisan command — including artisan list, key:generate, etc. —
        // was the original freeze cause. Module CLI commands should be registered
        // by the module's own provider only when needed (e.g. in a module:run command
        // that accepts a --tenant option), not unconditionally on every CLI invocation.
        if (! $isActive) {
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

        if ($this->composerModulePackages === null) {
            $installed = json_decode(file_get_contents($installedPath), true);
            $all       = $installed['packages'] ?? $installed;

            $this->composerModulePackages = is_array($all)
                ? array_values(array_filter($all, fn ($p) => ($p['extra']['modularity']['module'] ?? false) === true))
                : [];
        }

        foreach ($this->composerModulePackages as $package) {
            $name = $package['name'] ?? '';

            // Reject empty names and anything that doesn't look like a valid
            // Composer package name (vendor/package). This prevents path traversal
            // via a crafted installed.json entry containing ".." sequences.
            if ($name === '' || ! preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/i', $name)) {
                continue;
            }

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
