<?php

namespace Modularity\Core\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Modularity\Core\Tenancy\Exceptions\TenantNotResolvedException;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->isSet()) {
            // Tenant-unset means UNSCOPED — a query here returns every tenant's rows.
            // In strict mode (opt-in) we fail closed instead of silently leaking, so a
            // forgotten Tenant::set() throws rather than disabling isolation. Real
            // artisan commands are exempt (migrations, seeders and maintenance commands
            // legitimately run without a tenant); the test environment still enforces so
            // the guarantee is verifiable.
            $exemptConsole = app()->runningInConsole() && ! app()->runningUnitTests();

            if (config('modularity.tenancy.strict', false) && ! $exemptConsole) {
                throw new TenantNotResolvedException();
            }

            return;
        }

        $column = config('modularity.tenancy.column', 'tenant_id');

        $builder->where($model->getTable().'.'.$column, $context->id());
    }

    public static function creating(Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->isSet()) {
            return;
        }

        $column = config('modularity.tenancy.column', 'tenant_id');

        if (is_null($model->{$column})) {
            $model->{$column} = $context->id();
        }
    }
}
