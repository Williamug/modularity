<?php

namespace Modularity\Events;

use Modularity\Core\Module\ManifestDTO;

class ModuleInstalled
{
    public function __construct(public readonly ManifestDTO $manifest) {}
}
