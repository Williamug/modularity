<?php

namespace Modularity\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class MakeLivewireCommand extends Command
{
    protected $signature = 'module:make-livewire
                            {module : The PascalCase module name (e.g. Library)}
                            {name   : The PascalCase component name (e.g. UserList)}
                            {--path= : Override the modules base path}';

    protected $description = 'Scaffold a Livewire component inside an existing module';

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $module = $this->argument('module');
        $name   = $this->argument('name');

        $basePath   = $this->option('path') ?? config('modularity.modules_path', base_path('Modules'));
        $modulePath = rtrim($basePath, '/').'/'.$module;

        if (! $this->files->isDirectory($modulePath)) {
            $this->error("Module [{$module}] not found at [{$modulePath}].");

            return self::FAILURE;
        }

        $moduleSlug     = $this->toKebab($module);
        $componentKebab = $this->toKebab($name);

        $classPath = $modulePath."/src/Http/Livewire/{$name}.php";
        $viewPath  = $modulePath."/resources/views/livewire/{$componentKebab}.blade.php";

        if ($this->files->exists($classPath)) {
            $this->error("Component [{$name}] already exists at [{$classPath}].");

            return self::FAILURE;
        }

        $stubsBase = __DIR__.'/../../../stubs/module';

        $replacements = [
            '{{PascalName}}'        => $module,
            '{{kebab-slug}}'        => $moduleSlug,
            '{{ComponentPascalName}}' => $name,
            '{{component-kebab}}'   => $componentKebab,
        ];

        $this->writeFromStub(
            stub: $stubsBase.'/Http/Livewire/LivewireComponent.stub',
            target: $classPath,
            replacements: $replacements,
        );

        $this->writeFromStub(
            stub: $stubsBase.'/resources/views/livewire/component.blade.stub',
            target: $viewPath,
            replacements: $replacements,
        );

        $this->info("Livewire component [{$name}] created in module [{$module}].");
        $this->line('');
        $this->line('Register it in your ServiceProvider:');
        $this->line("  protected array \$livewireComponents = [");
        $this->line("      '{$moduleSlug}-{$componentKebab}' => \\Modules\\{$module}\\Http\\Livewire\\{$name}::class,");
        $this->line("  ];");
        $this->line('');
        $this->line('Use it in Blade (Livewire 3/4 auto-discovery):');
        $this->line("  <livewire:{$moduleSlug}-{$componentKebab} />");

        return self::SUCCESS;
    }

    private function writeFromStub(string $stub, string $target, array $replacements): void
    {
        $content = $this->files->get($stub);
        $content = str_replace(array_keys($replacements), array_values($replacements), $content);

        $this->files->ensureDirectoryExists(dirname($target));
        $this->files->put($target, $content);
    }

    private function toKebab(string $name): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
    }
}
