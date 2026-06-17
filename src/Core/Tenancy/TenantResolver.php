<?php

namespace Modularity\Core\Tenancy;

use Illuminate\Http\Request;
use Modularity\Core\Tenancy\Contracts\TenantResolverInterface;

class TenantResolver
{
    /** @param TenantResolverInterface[] $resolvers */
    public function __construct(private readonly array $resolvers) {}

    public function resolve(Request $request): ?int
    {
        foreach ($this->resolvers as $resolver) {
            $tenantId = $resolver->resolve($request);

            if ($tenantId !== null) {
                return $tenantId;
            }
        }

        return null;
    }
}
