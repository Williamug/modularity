<?php

namespace Modularity\Support\Abstracts;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modularity\Core\Module\ModuleManager;

abstract class ModuleServiceProvider extends ServiceProvider
{
    protected string $slug;

    protected string $version = '1.0.0';

    /** @var array<class-string, class-string[]> */
    protected array $listen = [];

    /** @var string[] Livewire component aliases => class names */
    protected array $livewireComponents = [];

    public function boot(): void
    {
        if (! $this->moduleIsActive()) {
            return;
        }

        $this->loadModuleRoutes();
        $this->loadModuleViews();
        $this->loadLivewireComponents();
        $this->registerModuleNavigation();
        $this->registerListeners();
    }

    protected function moduleIsActive(): bool
    {
        return app(ModuleManager::class)->active($this->slug);
    }

    protected function loadModuleRoutes(): void
    {
        $webRoutes = $this->modulePath('routes/web.php');
        $apiRoutes = $this->modulePath('routes/api.php');

        if (file_exists($webRoutes)) {
            Route::middleware('web')->group($webRoutes);
        }

        if (file_exists($apiRoutes)) {
            Route::middleware('api')->prefix('api')->group($apiRoutes);
        }
    }

    protected function loadModuleViews(): void
    {
        $viewsPath = $this->modulePath('resources/views');

        if (is_dir($viewsPath)) {
            $this->loadViewsFrom($viewsPath, $this->slug);
        }
    }

    protected function loadLivewireComponents(): void
    {
        if (empty($this->livewireComponents)) {
            return;
        }

        if (! class_exists(\Livewire\Livewire::class)) {
            return;
        }

        // Guard against double-registration across multiple boot() calls
        static $registered = [];

        foreach ($this->livewireComponents as $alias => $class) {
            $key = $this->slug.'.'.$alias;

            if (isset($registered[$key])) {
                continue;
            }

            \Livewire\Livewire::component($alias, $class);
            $registered[$key] = true;
        }
    }

    protected function registerModuleNavigation(): void
    {
        // Module authors override this method to add menu items via Module::menu()->add()
    }

    protected function registerListeners(): void
    {
        static $registeredListeners = [];

        foreach ($this->listen as $event => $listeners) {
            foreach ($listeners as $listener) {
                $key = $event.':'.$listener;

                if (isset($registeredListeners[$key])) {
                    continue;
                }

                Event::listen($event, $listener);
                $registeredListeners[$key] = true;
            }
        }
    }

    /**
     * Returns the absolute path to a file within this module's directory.
     * Override getModulePath() if the module.json is not adjacent to the provider.
     */
    protected function modulePath(string $relative = ''): string
    {
        $base = $this->getModulePath();

        return $relative ? rtrim($base, '/').'/'.$relative : $base;
    }

    protected function getModulePath(): string
    {
        // Default: the module directory is 2 levels up from the Providers/ directory
        // e.g. Modules/Library/src/Providers/LibraryServiceProvider.php → Modules/Library/
        $reflection = new \ReflectionClass(static::class);
        $providerDir = dirname($reflection->getFileName());

        return dirname($providerDir, 2);
    }
}
