<?php

namespace Modularity\Support\Traits;

use Modularity\Core\Tenancy\TenantScope;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (self $model) {
            TenantScope::creating($model);
        });
    }

    public function getTenantId(): ?int
    {
        $column = config('modularity.tenancy.column', 'tenant_id');

        return $this->{$column};
    }
}
