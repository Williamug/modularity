<?php

namespace Modularity\Core\Module;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Modularity\Core\Module\Exceptions\CircularDependencyException;
use Modularity\Core\Module\Exceptions\InvalidManifestException;
use Modularity\Core\Permissions\PermissionRegistry;

class ModuleLoader
{
    /** @var array<int, array<string, mixed>>|null Parsed modularity packages from installed.json */
    private ?array $composerModulePackages = null;

    public function __construct(
        private readonly Application $app,
        private readonly ModuleRegistry $registry,
        private readonly PermissionRegistry $permissions,
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

        // Never auto-boot module providers on a real artisan invocation. Booting every
        // installed module for every command (artisan list, key:generate, migrate, …) was
        // the original freeze/memory cause, and module routes/views aren't needed there.
        // We still boot during the test environment so the HTTP path is exercisable.
        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            return;
        }

        try {
            $sorted = (new DependencyGraph($candidates))->resolve();
        } catch (CircularDependencyException $e) {
            Log::error('[Modularity] '.$e->getMessage());

            return;
        }

        foreach ($sorted as $manifest) {
            $this->bootModule($manifest);
        }
    }

    private function bootModule(ManifestDTO $manifest): void
    {
        $installedRecord = $this->registry->getInstalledRecord($manifest->slug);

        if ($installedRecord?->isErrored()) {
            return;
        }

        // Register the module's providers for EVERY installed module, independent of the
        // current tenant. Routes, views and navigation are global registrations — the
        // tenant isn't known yet at boot (session/auth tenancy resolves in middleware).
        // Per-tenant access is enforced at request time by the `module.active` middleware,
        // and NavigationRegistry::forTenant() filters menu items per tenant when rendered.
        //
        // Permissions are registered here too so their Gate abilities exist on every
        // request (a host grants them via Gate::before / its own permission system).
        $this->permissions->registerForModule($manifest->slug, $manifest->permissions);

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
