<?php

namespace Modularity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modularity\Core\Module\ModuleManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates access to a module's routes by per-tenant activeness.
 *
 * A module's routes/views/navigation are registered for every installed module
 * (so URLs always resolve), but whether the *current tenant* may reach them is
 * decided here, at request time — after the tenant has been resolved into the
 * TenantContext. If the module is not active for the current tenant, the route
 * 404s, hiding its very existence.
 *
 * Usage (in a module's routes/web.php):
 *
 *     Route::middleware(['web', 'auth', 'module.active:library'])->group(...);
 */
class EnsureModuleActive
{
    public function __construct(private readonly ModuleManager $modules) {}

    public function handle(Request $request, Closure $next, string $slug): Response
    {
        abort_unless($this->modules->active($slug), 404);

        return $next($request);
    }
}
