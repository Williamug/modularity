<?php

namespace Modularity\Core\Module;

use Illuminate\Support\Facades\Log;
use Modularity\Models\ModuleMigrationLog;

class MigrationRunner
{
    public function runForModule(string $slug, string $migrationsPath): int
    {
        if (! is_dir($migrationsPath)) {
            return 0;
        }

        // Use the Laravel Migrator directly instead of Artisan::call('migrate').
        // Artisan::call() inside a test context bootstraps a second artisan kernel
        // inside an already-running application, which can deadlock on the SQLite
        // exclusive write lock when RefreshDatabase has wrapped the test in a
        // transaction. The Migrator service is lighter and works cleanly in tests.
        /** @var \Illuminate\Database\Migrations\Migrator $migrator */
        $migrator = app('migrator');

        $ranBefore = $migrator->getRepository()->getRan();

        try {
            $migrator->run([$migrationsPath]);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "[Modularity] Migration failed for module [{$slug}]: {$e->getMessage()}",
                0,
                $e
            );
        }

        $newlyRan = array_values(
            array_diff($migrator->getRepository()->getRan(), $ranBefore)
        );

        if (empty($newlyRan)) {
            return 0;
        }

        Log::info("[Modularity] Ran ".count($newlyRan)." migration(s) for module [{$slug}].");

        $batch = $this->nextBatch($slug);

        foreach ($newlyRan as $migrationName) {
            try {
                ModuleMigrationLog::create([
                    'module_slug'    => $slug,
                    'migration_file' => $migrationName.'.php',
                    'batch'          => $batch,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                // A concurrent process already logged this migration; skip.
            }
        }

        return count($newlyRan);
    }

    /**
     * Returns migration file paths not yet logged to ModuleMigrationLog for this module.
     * Useful for displaying "pending" status — the actual run decision uses the migrations table.
     */
    public function pendingForModule(string $slug, string $migrationsPath): array
    {
        if (! is_dir($migrationsPath)) {
            return [];
        }

        $files = glob($migrationsPath.'/*.php') ?: [];
        sort($files);

        $ran = ModuleMigrationLog::forModule($slug)
            ->pluck('migration_file')
            ->all();

        return array_values(
            array_filter($files, fn ($f) => ! in_array(basename($f), $ran, true))
        );
    }

    public function ranFilesForModule(string $slug): array
    {
        return ModuleMigrationLog::forModule($slug)
            ->pluck('migration_file')
            ->all();
    }

    private function nextBatch(string $slug): int
    {
        $max = ModuleMigrationLog::forModule($slug)->max('batch');

        return ($max ?? 0) + 1;
    }
}
