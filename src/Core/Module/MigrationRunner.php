<?php

namespace Modularity\Core\Module;

use Illuminate\Support\Facades\Artisan;
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

        Artisan::call('migrate', [
            '--path'     => $migrationsPath,
            '--realpath' => true,
            '--force'    => true,
        ]);

        $batch = $this->nextBatch($slug);

        foreach ($pending as $file) {
            ModuleMigrationLog::create([
                'module_slug'    => $slug,
                'migration_file' => basename($file),
                'batch'          => $batch,
            ]);
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
