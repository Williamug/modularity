<?php

namespace Modularity\Core\Tenancy\Resolvers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modularity\Core\Tenancy\Contracts\TenantResolverInterface;

class SubdomainTenantResolver implements TenantResolverInterface
{
    public function resolve(Request $request): ?int
    {
        $host   = $request->getHost();
        $parts  = explode('.', $host);

        if (count($parts) < 2) {
            return null;
        }

        $subdomain = $parts[0];

        // Reserved subdomains that should never be tenant slugs
        if (in_array($subdomain, ['www', 'api', 'admin', 'app'], true)) {
            return null;
        }

        return $this->lookupBySlug($subdomain);
    }

    private function lookupBySlug(string $slug): ?int
    {
        $modelClass = config('modularity.tenancy.model');

        if (! $modelClass || ! class_exists($modelClass)) {
            return null;
        }

        return Cache::remember(
            "modularity.tenant.subdomain.{$slug}",
            300,
            fn () => $modelClass::where('slug', $slug)->value('id')
        );
    }
}
