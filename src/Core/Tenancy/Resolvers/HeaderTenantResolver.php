<?php

namespace Modularity\Core\Tenancy\Resolvers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modularity\Core\Tenancy\Contracts\TenantResolverInterface;

class HeaderTenantResolver implements TenantResolverInterface
{
    public function resolve(Request $request): ?int
    {
        $header = $request->header('X-Tenant-ID');

        if ($header === null || ! ctype_digit((string) $header) || (int) $header <= 0) {
            return null;
        }

        $tenantId   = (int) $header;
        $modelClass = config('modularity.tenancy.model');

        if (! $modelClass || ! class_exists($modelClass)) {
            return null;
        }

        $exists = Cache::remember(
            "modularity.tenant.id.{$tenantId}",
            300,
            fn () => $modelClass::where('id', $tenantId)->exists()
        );

        return $exists ? $tenantId : null;
    }
}
