<?php

namespace Modularity\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modularity\Core\Lifecycle\ModuleRemover;
use Modularity\Core\Module\Exceptions\ModuleNotInstalledException;
use Modularity\Core\Module\Exceptions\ModuleStillActiveException;

class RemoveModuleCommand extends Command
{
  protected $signature = 'module:remove
                            {slug : The module slug to remove from the platform}
                            {--force : Deactivate all tenants first, then remove}';

  protected $description = 'Remove a module from the platform (does not roll back migrations)';

  public function handle(ModuleRemover $remover): int
  {
    $slug  = $this->argument('slug');
    $force = $this->option('force');

    if (! $force && ! $this->confirm("Are you sure you want to remove [{$slug}] from the platform?")) {
      $this->info('Aborted.');

      return self::SUCCESS;
    }

    try {
      $remover->remove($slug, $force);
      $this->info("Module [{$slug}] removed from the platform.");
    } catch (ModuleNotInstalledException $e) {
      $this->error($e->getMessage());

      return self::FAILURE;
    } catch (ModuleStillActiveException $e) {
      $this->error($e->getMessage());
      $this->line('Tip: use --force to auto-deactivate all tenants before removal.');

      return self::FAILURE;
    } catch (\Exception $e) {
      Log::error('[Modularity] Removal failed for ['.$slug.']: '.$e->getMessage(), ['exception' => $e]);
      $this->error('Removal failed: ' . $e->getMessage());

      return self::FAILURE;
    }

    return self::SUCCESS;
  }
}
