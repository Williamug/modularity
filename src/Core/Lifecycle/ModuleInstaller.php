<?php

namespace Modularity\Core\Lifecycle;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modularity\Core\Module\Exceptions\DependencyNotInstalledException;
use Modularity\Core\Module\Exceptions\IncompatibleModuleException;
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
        $this->validateCompatibility($manifest);

        $migrationsPath = $manifest->path.'/database/migrations';

        // Run migrations outside the transaction — DDL is non-transactional on
        // most databases. A failure here throws and aborts before any record
        // is written, leaving a clean slate for a retry.
        $this->migrationRunner->runForModule($slug, $migrationsPath);

        $checksum = hash_file('sha256', $manifest->path.'/module.json') ?: null;

        $record = DB::transaction(function () use ($slug, $manifest, $checksum): InstalledModule {
            $this->permissionRegistry->registerForModule($slug, $manifest->permissions);

            return InstalledModule::create([
                'slug'     => $slug,
                'name'     => $manifest->name,
                'version'  => $manifest->version,
                'checksum' => $checksum,
                'status'   => 'installed',
            ]);
        });

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

    private function validateCompatibility(ManifestDTO $manifest): void
    {
        $constraint = $manifest->compatibility;

        if ($constraint === '*' || $constraint === '') {
            return;
        }

        $platformVersion = config('modularity.version', '1.0.0');

        if (class_exists(\Composer\Semver\Semver::class)) {
            if (! \Composer\Semver\Semver::satisfies($platformVersion, $constraint)) {
                throw IncompatibleModuleException::version($manifest->slug, $constraint, $platformVersion);
            }

            return;
        }

        Log::warning(
            "[Modularity] Module [{$manifest->slug}] declares compatibility [{$constraint}] "
            .'but composer/semver is not available to verify it. Install composer/semver to enable enforcement.'
        );
    }
}
