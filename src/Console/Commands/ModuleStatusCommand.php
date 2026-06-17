<?php

namespace Modularity\Console\Commands;

use Illuminate\Console\Command;
use Modularity\Core\Module\ModuleRegistry;
use Modularity\Models\ModuleMigrationLog;
use Modularity\Models\TenantModule;

class ModuleStatusCommand extends Command
{
    protected $signature = 'modularity:status {slug : The module slug}';

    protected $description = 'Show detailed status of a module including tenant breakdown';

    public function handle(ModuleRegistry $registry): int
    {
        $slug     = $this->argument('slug');
        $manifest = $registry->getManifest($slug);
        $record   = $registry->getInstalledRecord($slug);

        if (! $manifest) {
            $this->error("Module [{$slug}] not found. Is it in the Modules/ path or installed via Composer?");

            return self::FAILURE;
        }

        $this->info("Module: {$manifest->name} [{$slug}]");
        $this->line("Description : {$manifest->description}");
        $this->line("Disk version : {$manifest->version}");
        $this->line("DB version   : ".($record?->version ?? 'Not installed'));
        $this->line("Status       : ".($record ? $record->status : 'discovered'));
        $this->line("Installed at : ".($record?->installed_at?->toDateTimeString() ?? 'N/A'));

        if ($manifest->version !== ($record?->version ?? $manifest->version)) {
            $this->warn("Version mismatch: disk={$manifest->version}, DB={$record->version}. Run: php artisan modularity:upgrade {$slug}");
        }

        $this->line('');
        $this->line('Dependencies:');

        if (empty($manifest->dependencies)) {
            $this->line('  None');
        } else {
            foreach ($manifest->dependencies as $dep) {
                $depSlug    = is_array($dep) ? $dep['slug'] : $dep;
                $installed  = $registry->isInstalled($depSlug) ? '<info>installed</info>' : '<error>missing</error>';
                $this->line("  - {$depSlug}: {$installed}");
            }
        }

        $this->line('');
        $this->line('Permissions:');

        if (empty($manifest->permissions)) {
            $this->line('  None declared');
        } else {
            foreach ($manifest->permissions as $perm) {
                $this->line("  - {$perm}");
            }
        }

        $this->line('');
        $this->line('Migration log:');

        $migrations = ModuleMigrationLog::forModule($slug)->orderBy('batch')->get();

        if ($migrations->isEmpty()) {
            $this->line('  No migrations run.');
        } else {
            $this->table(
                ['File', 'Batch', 'Ran At'],
                $migrations->map(fn ($m) => [$m->migration_file, $m->batch, $m->ran_at])->all()
            );
        }

        $this->line('');
        $this->line('Tenant activation:');

        $tenantModules = TenantModule::forModule($slug)->get();

        if ($tenantModules->isEmpty()) {
            $this->line('  No tenant records.');
        } else {
            $this->table(
                ['Tenant ID', 'Active', 'Activated At', 'Deactivated At'],
                $tenantModules->map(fn ($tm) => [
                    $tm->tenant_id,
                    $tm->active ? 'Yes' : 'No',
                    $tm->activated_at?->toDateTimeString() ?? '—',
                    $tm->deactivated_at?->toDateTimeString() ?? '—',
                ])->all()
            );
        }

        return self::SUCCESS;
    }
}
