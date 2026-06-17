<?php

namespace Modularity\Core\Tenancy\Resolvers;

use Illuminate\Http\Request;
use Modularity\Core\Tenancy\Contracts\TenantResolverInterface;

class HeaderTenantResolver implements TenantResolverInterface
{
    public function resolve(Request $request): ?int
    {
        $header = $request->header('X-Tenant-ID');

        if ($header === null || ! ctype_digit((string) $header)) {
            return null;
        }

        return (int) $header;
    }
}
