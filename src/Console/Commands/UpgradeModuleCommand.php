<?php

namespace Modularity\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modularity\Core\Lifecycle\ModuleUpgrader;
use Modularity\Core\Module\Exceptions\ModuleNotInstalledException;

class UpgradeModuleCommand extends Command
{
  protected $signature = 'module:upgrade {slug : The module slug to upgrade}';

  protected $description = 'Run pending migrations for an installed module and update its version';

  public function handle(ModuleUpgrader $upgrader): int
  {
    $slug = $this->argument('slug');

    $this->info("Upgrading module [{$slug}]...");

    try {
      $record = $upgrader->upgrade($slug);
      $this->info("Module [{$slug}] is now at version {$record->version}.");
    } catch (ModuleNotInstalledException $e) {
      $this->error($e->getMessage());

      return self::FAILURE;
    } catch (\Exception $e) {
      Log::error('[Modularity] Upgrade failed for ['.$slug.']: '.$e->getMessage(), ['exception' => $e]);
      $this->error('Upgrade failed: ' . $e->getMessage());

      return self::FAILURE;
    }

    return self::SUCCESS;
  }
}
