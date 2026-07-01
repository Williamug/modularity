<?php

namespace Modularity;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modularity\Core\Events\ModuleAwareListener;
use Modularity\Core\Lifecycle\ModuleActivator;
use Modularity\Core\Lifecycle\ModuleDeactivator;
use Modularity\Core\Lifecycle\ModuleInstaller;
use Modularity\Core\Lifecycle\ModuleRemover;
use Modularity\Core\Lifecycle\ModuleUpgrader;
use Modularity\Core\Module\MigrationRunner;
use Modularity\Core\Module\ModuleLoader;
use Modularity\Core\Module\ModuleManager;
use Modularity\Core\Module\ModuleRegistry;
use Modularity\Core\Navigation\NavigationRegistry;
use Modularity\Core\Permissions\Contracts\PermissionDriverInterface;
use Modularity\Core\Permissions\Drivers\GatePermissionDriver;
use Modularity\Core\Permissions\Drivers\NullPermissionDriver;
use Modularity\Core\Permissions\Drivers\SpatiePermissionDriver;
use Modularity\Core\Permissions\PermissionRegistry;
use Modularity\Core\Tenancy\Contracts\TenantResolverInterface;
use Modularity\Core\Tenancy\Resolvers\DomainTenantResolver;
use Modularity\Core\Tenancy\Resolvers\HeaderTenantResolver;
use Modularity\Core\Tenancy\Resolvers\SessionTenantResolver;
use Modularity\Core\Tenancy\Resolvers\SubdomainTenantResolver;
use Modularity\Core\Tenancy\TenantContext;
use Modularity\Core\Tenancy\TenantResolver;
use Modularity\Events\ModuleActivated;
use Modularity\Events\ModuleDeactivated;
use Modularity\Events\ModuleInstalled;
use Modularity\Events\ModuleRemoved;
use Modularity\Events\ModuleUpgraded;
use Modularity\Listeners\CacheInvalidationListener;
use Modularity\Marketplace\Contracts\LicenseVerifierInterface;
use Modularity\Marketplace\Contracts\MarketplaceClientInterface;
use Modularity\Marketplace\Contracts\SubscriptionManagerInterface;
use Modularity\Marketplace\NullLicenseVerifier;
use Modularity\Marketplace\NullMarketplaceClient;
use Modularity\Marketplace\NullSubscriptionManager;

class ModularityServiceProvider extends ServiceProvider
{
    public const VERSION = '1.0.0';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/modularity.php', 'modularity');

        $this->registerCoreServices();
        $this->registerMarketplaceBindings();
        $this->registerPermissionDriver();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->publishAssets();
        $this->registerCommands();
        $this->registerMiddlewareAlias();
        $this->registerCacheInvalidationListeners();
        $this->bootModules();
    }

    private function registerCoreServices(): void
    {
        // Bind the concrete classes as the singletons, then expose the dotted-name
        // aliases pointing AT them. Binding the dotted name to the concrete string
        // *and* aliasing the concrete back to the dotted name (as was done before)
        // creates a resolution cycle — the container ping-pongs make()->resolve()
        // forever and exhausts memory on the first resolution.
        $this->app->singleton(TenantContext::class);

        $this->app->singleton(ModuleRegistry::class);

        $this->app->singleton(NavigationRegistry::class);

        $this->app->singleton('modularity.resolver', function ($app) {
            $resolverNames = config('modularity.tenancy.resolvers', ['subdomain', 'domain', 'header', 'session']);
            $resolvers = array_map(fn ($name) => $this->makeResolver($name), $resolverNames);

            return new TenantResolver($resolvers);
        });

        $this->app->singleton('modularity.manager', function ($app) {
            return new ModuleManager(
                registry:    $app->make('modularity.registry'),
                tenantContext: $app->make('modularity.tenant'),
                navigation:  $app->make('modularity.navigation'),
                permissions: $app->make('modularity.permissions'),
            );
        });

        $this->app->singleton('modularity.loader', function ($app) {
            return new ModuleLoader(
                app:         $app,
                registry:    $app->make('modularity.registry'),
                permissions: $app->make('modularity.permissions'),
            );
        });

        $this->app->singleton(MigrationRunner::class);

        $this->app->singleton(ModuleInstaller::class, function ($app) {
            return new ModuleInstaller(
                registry:          $app->make('modularity.registry'),
                migrationRunner:   $app->make(MigrationRunner::class),
                permissionRegistry: $app->make('modularity.permissions'),
                events:            $app->make('events'),
            );
        });

        $this->app->singleton(ModuleActivator::class, function ($app) {
            return new ModuleActivator(
                registry:      $app->make('modularity.registry'),
                subscriptions: $app->make(SubscriptionManagerInterface::class),
                events:        $app->make('events'),
            );
        });

        $this->app->singleton(ModuleDeactivator::class, function ($app) {
            return new ModuleDeactivator(
                events: $app->make('events'),
            );
        });

        $this->app->singleton(ModuleUpgrader::class, function ($app) {
            return new ModuleUpgrader(
                registry:        $app->make('modularity.registry'),
                migrationRunner: $app->make(MigrationRunner::class),
                events:          $app->make('events'),
            );
        });

        $this->app->singleton(ModuleRemover::class, function ($app) {
            return new ModuleRemover(
                registry:    $app->make('modularity.registry'),
                deactivator: $app->make(ModuleDeactivator::class),
                events:      $app->make('events'),
            );
        });

        // Expose dotted-name aliases pointing at the concrete singletons.
        $this->app->alias(TenantContext::class, 'modularity.tenant');
        $this->app->alias(ModuleRegistry::class, 'modularity.registry');
        $this->app->alias(NavigationRegistry::class, 'modularity.navigation');
        // Alias the concrete TenantResolver to its closure-built binding so the
        // container can autowire ResolveTenantMiddleware (which type-hints the
        // concrete class). Without this, autowiring fails on the resolver's
        // `array $resolvers` constructor arg and the `resolve.tenant` middleware 500s.
        $this->app->alias('modularity.resolver', TenantResolver::class);
        // ModuleManager is bound under the dotted name via a closure (buildable),
        // so aliasing the concrete class to it is safe and does not cycle.
        $this->app->alias(ModuleManager::class, 'modularity.manager');
    }

    private function registerMarketplaceBindings(): void
    {
        $clientClass = config('modularity.marketplace.client', NullMarketplaceClient::class);

        $this->app->singleton(MarketplaceClientInterface::class, $clientClass);
        $this->app->singleton(LicenseVerifierInterface::class, NullLicenseVerifier::class);
        $this->app->singleton(SubscriptionManagerInterface::class, NullSubscriptionManager::class);
    }

    private function registerPermissionDriver(): void
    {
        $this->app->singleton('modularity.permissions', function ($app) {
            return new PermissionRegistry(
                $app->make(PermissionDriverInterface::class),
                $app->make(ModuleRegistry::class),
            );
        });

        $this->app->alias('modularity.permissions', PermissionRegistry::class);

        $this->app->singleton(PermissionDriverInterface::class, function ($app) {
            $driver = config('modularity.permissions.driver', 'gate');

            return match ($driver) {
                'gate'   => new GatePermissionDriver(),
                'spatie' => new SpatiePermissionDriver(),
                'null'   => new NullPermissionDriver(),
                // Any other value is treated as a custom FQCN implementing
                // PermissionDriverInterface, resolved through the container.
                default  => $app->make($driver),
            };
        });
    }

    private function publishAssets(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/modularity.php' => config_path('modularity.php'),
        ], 'modularity-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'modularity-migrations');

        $this->publishes([
            __DIR__.'/../stubs' => base_path('stubs/modularity'),
        ], 'modularity-stubs');
    }

    private function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            \Modularity\Console\Commands\MakeModuleCommand::class,
            \Modularity\Console\Commands\MakeLivewireCommand::class,
            \Modularity\Console\Commands\InstallModuleCommand::class,
            \Modularity\Console\Commands\ActivateModuleCommand::class,
            \Modularity\Console\Commands\DeactivateModuleCommand::class,
            \Modularity\Console\Commands\UpgradeModuleCommand::class,
            \Modularity\Console\Commands\RemoveModuleCommand::class,
            \Modularity\Console\Commands\ListModulesCommand::class,
            \Modularity\Console\Commands\ModuleStatusCommand::class,
        ]);
    }

    private function registerMiddlewareAlias(): void
    {
        $router = $this->app['router'];

        if (method_exists($router, 'aliasMiddleware')) {
            $router->aliasMiddleware('resolve.tenant', \Modularity\Http\Middleware\ResolveTenantMiddleware::class);
            $router->aliasMiddleware('module.active', \Modularity\Http\Middleware\EnsureModuleActive::class);
        }
    }

    private function registerCacheInvalidationListeners(): void
    {
        $listener = fn () => $this->app->make(CacheInvalidationListener::class);

        Event::listen(ModuleInstalled::class,   fn ($e) => $listener()->handleModuleInstalled($e));
        Event::listen(ModuleRemoved::class,     fn ($e) => $listener()->handleModuleRemoved($e));
        Event::listen(ModuleUpgraded::class,    fn ($e) => $listener()->handleModuleUpgraded($e));
        Event::listen(ModuleActivated::class,   fn ($e) => $listener()->handleModuleActivated($e));
        Event::listen(ModuleDeactivated::class, fn ($e) => $listener()->handleModuleDeactivated($e));
    }

    private function bootModules(): void
    {
        $loader = $this->app->make('modularity.loader');
        $loader->discover();
        $loader->boot();
    }

    private function makeResolver(string $name): TenantResolverInterface
    {
        return match ($name) {
            'subdomain' => new SubdomainTenantResolver(),
            'domain'    => new DomainTenantResolver(),
            'header'    => new HeaderTenantResolver(),
            'session'   => new SessionTenantResolver(),
            default     => $this->app->make($name), // Allow FQCN for custom resolvers
        };
    }
}
