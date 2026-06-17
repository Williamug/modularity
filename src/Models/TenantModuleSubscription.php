<?php

namespace Modularity\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TenantModuleSubscription extends Model
{
    protected $table = 'modularity_tenant_module_subscriptions';

    protected $fillable = [
        'tenant_id',
        'module_slug',
        'status',
        'billing_cycle',
        'starts_at',
        'expires_at',
    ];

    protected $casts = [
        'starts_at'  => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeTrial(Builder $query): Builder
    {
        return $query->where('status', 'trial');
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function isValid(): bool
    {
        if (in_array($this->status, ['active', 'trial', 'free'])) {
            if ($this->expires_at !== null) {
                return $this->expires_at->isFuture();
            }

            return true;
        }

        return false;
    }
}
