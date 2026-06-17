<?php

namespace Modularity\Core\Tenancy\Resolvers;

use Illuminate\Http\Request;
use Modularity\Core\Tenancy\Contracts\TenantResolverInterface;

class SessionTenantResolver implements TenantResolverInterface
{
    public function resolve(Request $request): ?int
    {
        if (! $request->hasSession()) {
            return null;
        }

        $tenantId = $request->session()->get('modularity_tenant_id');

        if (! is_int($tenantId) && ! ctype_digit((string) $tenantId)) {
            return null;
        }

        return (int) $tenantId;
    }
}
