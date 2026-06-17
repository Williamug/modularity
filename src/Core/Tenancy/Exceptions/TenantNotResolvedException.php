<?php

namespace Modularity\Core\Tenancy\Exceptions;

use RuntimeException;

class TenantNotResolvedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No tenant has been resolved for the current context. Ensure ResolveTenantMiddleware is applied or pass --tenant= to Artisan commands.');
    }
}
