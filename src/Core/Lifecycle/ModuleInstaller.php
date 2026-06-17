<?php

namespace Modularity\Core\Lifecycle;

use Illuminate\Contracts\Events\Dispatcher;
use Modularity\Core\Module\Exceptions\DependencyNotInstalledException;
use Modularity\Core\Module\Exceptions\ModuleNotFoundException;
use Modularity\Core\Module\ManifestDTO;
use Modularity\Core\Module\MigrationRunner;
use Modularity\Core\Module\ModuleManifest;
use Modularity\Core\Module\ModuleRegistry;
use Modularity\Core\Permissions\PermissionRegistry;
use Modularity\Events\ModuleInstalled;
use Modularity\Models\InstalledModule;

class ModuleInstaller
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly MigrationRunner $migrationRunner,
        private readonly PermissionRegistry $permissionRegistry,
        private readonly Dispatcher $events,
    ) {}

    public function install(string $slug, ?string $path = null): InstalledModule
    {
        $manifest = $this->resolveManifest($slug, $path);

        // Idempotent: if already installed, return existing record
        $existing = $this->registry->getInstalledRecord($slug);

        if ($existing) {
            return $existing;
        }

        $this->validateDependencies($manifest);

        $migrationsPath = $manifest->path.'/database/migrations';
        $this->migrationRunner->runForModule($slug, $migrationsPath);

        $this->permissionRegistry->registerForModule($slug, $manifest->permissions);

        $checksum = md5_file($manifest->path.'/module.json') ?: null;

        $record = InstalledModule::create([
            'slug'         => $slug,
            'name'         => $manifest->name,
            'version'      => $manifest->version,
            'checksum'     => $checksum,
            'status'       => 'installed',
        ]);

        $this->registry->invalidateInstalled();

        $this->events->dispatch(new ModuleInstalled($manifest));

        return $record;
    }

    private function resolveManifest(string $slug, ?string $path): ManifestDTO
    {
        if ($path !== null) {
            return ModuleManifest::parse($path);
        }

        $manifest = $this->registry->getManifest($slug);

        if ($manifest === null) {
            throw ModuleNotFoundException::slug($slug);
        }

        return $manifest;
    }

    private function validateDependencies(ManifestDTO $manifest): void
    {
        foreach ($manifest->dependencySlugs() as $depSlug) {
            if (! $this->registry->isInstalled($depSlug)) {
                throw DependencyNotInstalledException::missing($manifest->slug, $depSlug);
            }
        }
    }
}
