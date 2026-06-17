<?php

namespace Modularity\Core\Tenancy\Resolvers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modularity\Core\Tenancy\Contracts\TenantResolverInterface;

class DomainTenantResolver implements TenantResolverInterface
{
    public function resolve(Request $request): ?int
    {
        $domain = $request->getHost();

        return $this->lookupByDomain($domain);
    }

    private function lookupByDomain(string $domain): ?int
    {
        $modelClass = config('modularity.tenancy.model');

        if (! $modelClass || ! class_exists($modelClass)) {
            return null;
        }

        return Cache::remember(
            "modularity.tenant.domain.{$domain}",
            300,
            fn () => $modelClass::where('domain', $domain)->value('id')
        );
    }
}
