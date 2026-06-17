<?php

namespace Modularity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modularity\Core\Tenancy\TenantContext;
use Modularity\Core\Tenancy\TenantResolver;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantMiddleware
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $this->resolver->resolve($request);

        if ($tenantId !== null) {
            $this->context->set($tenantId);
        }

        $response = $next($request);

        // Reset context after request to prevent bleed in long-running processes
        $this->context->forget();

        return $response;
    }
}
