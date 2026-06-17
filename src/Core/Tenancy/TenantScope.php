<?php

namespace Modularity\Core\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->isSet()) {
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
