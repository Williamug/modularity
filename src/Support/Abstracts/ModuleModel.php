<?php

namespace Modularity\Support\Abstracts;

use Illuminate\Database\Eloquent\Model;
use Modularity\Support\Traits\BelongsToTenant;

abstract class ModuleModel extends Model
{
    use BelongsToTenant;
}
