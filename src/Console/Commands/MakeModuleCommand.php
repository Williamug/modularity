<?php

namespace Modularity\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class MakeModuleCommand extends Command
{
  protected $signature = 'module:make-module
                            {name : The PascalCase module name (e.g. Library)}
                            {--path= : Override the modules base path}
                            {--livewire : Scaffold a default Livewire component alongside the module}';

  protected $description = 'Scaffold a new Modularity module';

  public function __construct(private readonly Filesystem $files)
  {
    parent::__construct();
  }

  public function handle(): int
  {
    $name = $this->argument('name');
    $slug = $this->toSlug($name);
    $basePath = $this->option('path') ?? config('modularity.modules_path', base_path('Modules'));
    $modulePath = rtrim($basePath, '/') . '/' . $name;

    if ($this->files->isDirectory($modulePath)) {
      $this->error("Module [{$name}] already exists at [{$modulePath}].");

      return self::FAILURE;
    }

    $this->createDirectories($modulePath);
    $this->createFiles($modulePath, $name, $slug);

    $this->info("Module [{$name}] scaffolded at [{$modulePath}].");
    $this->line('Next steps:');
    $this->line("  1. Register autoloading in composer.json: \"Modules\\\\{$name}\\\\\": \"Modules/{$name}/src/\"");
    $this->line("  2. composer dump-autoload");
    $this->line("  3. php artisan module:install {$slug}");

    if ($this->option('livewire')) {
      $componentName = $name . 'Index';
      $componentKebab = $slug . '-index';
      $this->line('');
      $this->line("Livewire component [{$componentName}] generated.");
      $this->line("  Register it in {$name}ServiceProvider.php:");
      $this->line("    protected array \$livewireComponents = [");
      $this->line("        '{$componentKebab}' => \\Modules\\{$name}\\Http\\Livewire\\{$componentName}::class,");
      $this->line("    ];");
    }

    return self::SUCCESS;
  }

  private function createDirectories(string $modulePath): void
  {
    $dirs = [
      'src/Http/Controllers',
      'src/Http/Livewire',
      'src/Models',
      'src/Services',
      'src/Events',
      'src/Listeners',
      'src/Policies',
      'src/Providers',
      'database/migrations',
      'routes',
      'resources/views',
      'tests',
    ];

    foreach ($dirs as $dir) {
      $this->files->makeDirectory($modulePath . '/' . $dir, 0755, true, true);
    }
  }

  private function createFiles(string $modulePath, string $name, string $slug): void
  {
    $stubsBase = __DIR__ . '/../../../stubs/module';

    $replacements = [
      '{{PascalName}}'   => $name,
      '{{kebab-slug}}'   => $slug,
      '{{snake_name}}'   => $this->toSnake($name),
      '{{table_prefix}}' => $slug . '_',
    ];

    $files = [
      'module.json.stub'                 => 'module.json',
      'ServiceProvider.stub'             => "src/Providers/{$name}ServiceProvider.php",
      'Controller.stub'                  => "src/Http/Controllers/{$name}Controller.php",
      'Model.stub'                       => "src/Models/{$name}.php",
      'Event.stub'                       => "src/Events/{$name}Created.php",
      'Listener.stub'                    => "src/Listeners/On{$name}Created.php",
      'Policy.stub'                      => "src/Policies/{$name}Policy.php",
      'migration.create.stub'            => 'database/migrations/' . date('Y_m_d_His') . "_create_{$slug}_table.php",
      'routes/web.stub'                  => 'routes/web.php',
      'routes/api.stub'                  => 'routes/api.php',
      'resources/views/index.blade.stub' => 'resources/views/index.blade.php',
    ];

    if ($this->option('livewire')) {
      $componentName = $name . 'Index';
      $componentKebab = $slug . '-index';

      $replacements['{{ComponentPascalName}}'] = $componentName;
      $replacements['{{component-kebab}}']     = $componentKebab;

      $files['Http/Livewire/LivewireComponent.stub']               = "src/Http/Livewire/{$componentName}.php";
      $files['resources/views/livewire/component.blade.stub']      = "resources/views/livewire/{$componentKebab}.blade.php";
    }

    foreach ($files as $stub => $target) {
      $stubPath = $stubsBase . '/' . $stub;
      $targetPath = $modulePath . '/' . $target;

      if (! $this->files->exists($stubPath)) {
        continue;
      }

      $content = $this->files->get($stubPath);
      $content = str_replace(array_keys($replacements), array_values($replacements), $content);

      $this->files->ensureDirectoryExists(dirname($targetPath));
      $this->files->put($targetPath, $content);
    }
  }

  private function toSlug(string $name): string
  {
    return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
  }

  private function toSnake(string $name): string
  {
    return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
  }
}
