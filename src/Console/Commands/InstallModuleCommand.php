<?php

namespace Modularity\Console\Commands;

use Illuminate\Console\Command;
use Modularity\Core\Lifecycle\ModuleActivator;
use Modularity\Core\Lifecycle\ModuleInstaller;
use Modularity\Core\Module\Exceptions\DependencyNotInstalledException;
use Modularity\Core\Module\Exceptions\ModuleNotFoundException;

class InstallModuleCommand extends Command
{
  protected $signature = 'module:install
                            {slug : The module slug to install}
                            {--tenant= : Also activate for this tenant ID after installing}
                            {--path= : Path to the module directory (optional, auto-discovered if omitted)}';

  protected $description = 'Install a module on the platform (runs migrations, seeds permissions)';

  public function handle(ModuleInstaller $installer, ModuleActivator $activator): int
  {
    $slug   = $this->argument('slug');
    $path   = $this->option('path');
    $tenant = $this->option('tenant');

    $this->info("Installing module [{$slug}]...");

    try {
      $record = $installer->install($slug, $path);
    } catch (ModuleNotFoundException $e) {
      $this->error($e->getMessage());

      return self::FAILURE;
    } catch (DependencyNotInstalledException $e) {
      $this->error($e->getMessage());

      return self::FAILURE;
    } catch (\Exception $e) {
      $this->error('Installation failed: ' . $e->getMessage());

      return self::FAILURE;
    }

    $this->info("Module [{$record->slug}] v{$record->version} installed successfully.");

    if ($tenant) {
      $this->info("Activating for tenant [{$tenant}]...");

      try {
        $activator->activate($slug, (int) $tenant);
        $this->info("Module [{$slug}] activated for tenant [{$tenant}].");
      } catch (\Exception $e) {
        $this->warn("Activation failed: " . $e->getMessage());
      }
    }

    return self::SUCCESS;
  }
}
