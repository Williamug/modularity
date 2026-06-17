<?php

namespace Modularity\Console\Commands;

use Illuminate\Console\Command;
use Modularity\Core\Lifecycle\ModuleDeactivator;

class DeactivateModuleCommand extends Command
{
    protected $signature = 'modularity:deactivate
                            {slug : The module slug}
                            {--tenant= : The tenant ID to deactivate for}
                            {--all-tenants : Deactivate for all tenants}';

    protected $description = 'Deactivate a module for a specific tenant (never deletes code or data)';

    public function handle(ModuleDeactivator $deactivator): int
    {
        $slug      = $this->argument('slug');
        $tenantId  = $this->option('tenant');
        $allTenants = $this->option('all-tenants');

        if (! $tenantId && ! $allTenants) {
            $this->error('Provide --tenant=ID or --all-tenants.');

            return self::FAILURE;
        }

        try {
            if ($allTenants) {
                $deactivator->deactivateAll($slug);
                $this->info("Module [{$slug}] deactivated for all tenants.");
            } else {
                $deactivator->deactivate($slug, (int) $tenantId);
                $this->info("Module [{$slug}] deactivated for tenant [{$tenantId}].");
            }
        } catch (\Exception $e) {
            $this->error('Deactivation failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
