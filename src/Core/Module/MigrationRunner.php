<?php

namespace Modularity\Core\Module;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Modularity\Models\ModuleMigrationLog;

class MigrationRunner
{
    public function runForModule(string $slug, string $migrationsPath): int
    {
        if (! is_dir($migrationsPath)) {
            return 0;
        }

        $pending = $this->pendingForModule($slug, $migrationsPath);

        if (empty($pending)) {
            return 0;
        }

        try {
            $exitCode = Artisan::call('migrate', [
                '--path'     => $migrationsPath,
                '--realpath' => true,
                '--force'    => true,
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "[Modularity] Migration failed for module [{$slug}]: {$e->getMessage()}",
                0,
                $e
            );
        }

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                "[Modularity] Migration failed for module [{$slug}] (exit code: {$exitCode})."
            );
        }

        Log::info("[Modularity] Ran ".count($pending)." migration(s) for module [{$slug}].");

        $batch = $this->nextBatch($slug);

        foreach ($pending as $file) {
            try {
                ModuleMigrationLog::create([
                    'module_slug'    => $slug,
                    'migration_file' => basename($file),
                    'batch'          => $batch,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                // A concurrent process already logged this migration; skip.
            }
        }

        return count($pending);
    }

    /**
     * Returns migration file paths that have not yet been logged for this module.
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

        return array_filter($files, fn ($f) => ! in_array(basename($f), $ran, true));
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
