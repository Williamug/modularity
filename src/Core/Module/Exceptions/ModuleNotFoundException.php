<?php

namespace Modularity\Core\Module\Exceptions;

use RuntimeException;

class ModuleNotFoundException extends RuntimeException
{
    public static function slug(string $slug): self
    {
        return new self("Module [{$slug}] was not found in the registry. Ensure it is discoverable in your Modules/ path or installed via Composer.");
    }
}
