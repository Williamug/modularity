<?php

namespace Modularity\Core\Tenancy\Contracts;

use Illuminate\Http\Request;

interface TenantResolverInterface
{
    public function resolve(Request $request): ?int;
}
