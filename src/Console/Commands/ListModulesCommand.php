<?php

namespace Modularity\Console\Commands;

use Illuminate\Console\Command;
use Modularity\Core\Module\ModuleRegistry;
use Modularity\Models\TenantModule;

class ListModulesCommand extends Command
{
  protected $signature = 'module:list {--tenant= : Filter to show activation status for a specific tenant}';

  protected $description = 'List all discovered, installed, and active modules';

  public function handle(ModuleRegistry $registry): int
  {
    $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;
    $rows     = [];

    // Batch-load all active tenant counts in a single grouped query
    $activeCounts = TenantModule::active()
      ->selectRaw('module_slug, COUNT(*) as count')
      ->groupBy('module_slug')
      ->pluck('count', 'module_slug')
      ->all();

    foreach ($registry->allDiscovered() as $slug => $manifest) {
      $isInstalled = $registry->isInstalled($slug);
      $isActive    = $tenantId !== null && $isInstalled
        ? $registry->activeFor($slug, $tenantId)
        : null;

      $activeTenantCount = $isInstalled ? (int) ($activeCounts[$slug] ?? 0) : 0;

      $status = 'discovered';

      if ($isInstalled) {
        $status = $isActive === true ? '<info>active</info>' : '<comment>installed</comment>';
      }

      $record = $registry->getInstalledRecord($slug);

      $rows[] = [
        $slug,
        $manifest->name,
        $record?->version ?? $manifest->version,
        $status,
        $activeTenantCount,
      ];
    }

    if (empty($rows)) {
      $this->warn('No modules discovered. Add modules to ' . config('modularity.modules_path') . ' or install via Composer.');

      return self::SUCCESS;
    }

    $headers = ['Slug', 'Name', 'Version', 'Status', 'Active Tenants'];

    $this->table($headers, $rows);

    return self::SUCCESS;
  }
}
