<?php

namespace Modularity\Core\Module\Exceptions;

use RuntimeException;

class ModuleStillActiveException extends RuntimeException
{
    public static function forTenants(string $slug, array $tenantIds): self
    {
        $list = implode(', ', $tenantIds);

        return new self("Module [{$slug}] is still active for tenant(s): [{$list}]. Deactivate it for all tenants first, or use --force.");
    }
}
