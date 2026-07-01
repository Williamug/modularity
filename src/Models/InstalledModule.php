<?php

namespace Modularity\Models;

use Illuminate\Database\Eloquent\Model;

class InstalledModule extends Model
{
    public $timestamps = false;

    protected $table = 'modularity_installed_modules';

    // 'installed_at' is intentionally NOT fillable — it is set by the database
    // default (useCurrent) so install timestamps cannot be spoofed via mass assignment.
    protected $fillable = [
        'slug',
        'name',
        'version',
        'checksum',
        'status',
    ];

    protected $casts = [
        'installed_at' => 'datetime',
    ];

    public function isErrored(): bool
    {
        return $this->status === 'errored';
    }
}
