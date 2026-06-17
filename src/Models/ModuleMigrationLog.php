<?php

namespace Modularity\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ModuleMigrationLog extends Model
{
    public $timestamps = false;

    protected $table = 'modularity_migration_log';

    protected $fillable = [
        'module_slug',
        'migration_file',
        'batch',
        'ran_at',
    ];

    protected $casts = [
        'ran_at' => 'datetime',
    ];

    public function scopeForModule(Builder $query, string $slug): Builder
    {
        return $query->where('module_slug', $slug);
    }
}
