<?php

namespace Modularity\Support\Facades;

use Illuminate\Support\Facades\Facade;
use Modularity\Core\Tenancy\TenantContext;

/**
 * @method static void set(int $tenantId)
 * @method static int|null id()
 * @method static bool isSet()
 * @method static void forget()
 * @method static int assertSet()
 *
 * @see TenantContext
 */
class Tenant extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'modularity.tenant';
    }
}
