<?php

namespace Modularity\Models;

use Illuminate\Database\Eloquent\Model;

class InstalledModule extends Model
{
    public $timestamps = false;

    protected $table = 'modularity_installed_modules';

    protected $fillable = [
        'slug',
        'name',
        'version',
        'checksum',
        'status',
        'installed_at',
    ];

    protected $casts = [
        'installed_at' => 'datetime',
    ];

    public function isErrored(): bool
    {
        return $this->status === 'errored';
    }
}
