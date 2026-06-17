<?php

namespace Modularity\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TenantModule extends Model
{
    public $timestamps = false;

    protected $table = 'modularity_tenant_modules';

    protected $fillable = [
        'tenant_id',
        'module_slug',
        'active',
        'settings',
        'activated_at',
        'deactivated_at',
    ];

    protected $casts = [
        'active'         => 'boolean',
        'settings'       => 'array',
        'activated_at'   => 'datetime',
        'deactivated_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForModule(Builder $query, string $slug): Builder
    {
        return $query->where('module_slug', $slug);
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }
}
