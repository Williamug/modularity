<?php

namespace Modularity\Console\Commands;

use Illuminate\Console\Command;
use Modularity\Core\Lifecycle\ModuleActivator;
use Modularity\Core\Module\Exceptions\ModuleNotInstalledException;

class ActivateModuleCommand extends Command
{
  protected $signature = 'module:activate
                            {slug : The module slug}
                            {--tenant= : The tenant ID to activate the module for (required)}';

  protected $description = 'Activate a module for a specific tenant';

  public function handle(ModuleActivator $activator): int
  {
    $slug     = $this->argument('slug');
    $tenantId = $this->option('tenant');

    if (! $tenantId) {
      $this->error('--tenant= is required. Example: php artisan modularity:activate library --tenant=1');

      return self::FAILURE;
    }

    try {
      $activator->activate($slug, (int) $tenantId);
      $this->info("Module [{$slug}] activated for tenant [{$tenantId}].");
    } catch (ModuleNotInstalledException $e) {
      $this->error($e->getMessage());

      return self::FAILURE;
    } catch (\Exception $e) {
      $this->error('Activation failed: ' . $e->getMessage());

      return self::FAILURE;
    }

    return self::SUCCESS;
  }
}
