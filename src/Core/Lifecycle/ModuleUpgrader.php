<?php

namespace Modularity\Core\Lifecycle;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Modularity\Core\Module\Exceptions\ModuleNotInstalledException;
use Modularity\Core\Module\MigrationRunner;
use Modularity\Core\Module\ModuleRegistry;
use Modularity\Events\ModuleUpgraded;
use Modularity\Models\InstalledModule;

class ModuleUpgrader
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly MigrationRunner $migrationRunner,
        private readonly Dispatcher $events,
    ) {}

    public function upgrade(string $slug): InstalledModule
    {
        $record = $this->registry->getInstalledRecord($slug);

        if (! $record) {
            throw ModuleNotInstalledException::slug($slug);
        }

        $manifest = $this->registry->getManifest($slug);

        $oldVersion = $record->version;
        $newVersion = $manifest?->version ?? $oldVersion;

        $count = 0;

        if ($manifest !== null) {
            $migrationsPath = $manifest->path.'/database/migrations';

            if (is_dir($migrationsPath)) {
                $count = $this->migrationRunner->runForModule($slug, $migrationsPath);
            }
        }

        if ($oldVersion !== $newVersion) {
            $record->update(['version' => $newVersion]);
        }

        if ($count > 0 || $oldVersion !== $newVersion) {
            Log::info("[Modularity] Module [{$slug}] upgraded from {$oldVersion} to {$newVersion} ({$count} migrations run).");

            // CacheInvalidationListener clears the installed + per-tenant caches
            // in response to this event.
            $this->events->dispatch(new ModuleUpgraded($slug, $oldVersion, $newVersion));
        }

        return $record->fresh() ?? $record;
    }
}
