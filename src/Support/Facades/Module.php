<?php

namespace Modularity\Support\Facades;

use Illuminate\Support\Facades\Facade;
use Modularity\Core\Module\ModuleManager;
use Modularity\Core\Navigation\NavigationRegistry;
use Modularity\Core\Permissions\PermissionRegistry;

/**
 * @method static bool active(string $slug)
 * @method static bool activeFor(string $slug, int $tenantId)
 * @method static bool installed(string $slug)
 * @method static bool discovered(string $slug)
 * @method static NavigationRegistry menu()
 * @method static PermissionRegistry permissions()
 * @method static mixed config(string $slug, string $key, mixed $default = null)
 *
 * @see ModuleManager
 */
class Module extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'modularity.manager';
    }
}
