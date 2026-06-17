<?php

namespace Modularity\Core\Module\Exceptions;

use RuntimeException;

class ModuleAlreadyInstalledException extends RuntimeException
{
    public static function slug(string $slug): self
    {
        return new self("Module [{$slug}] is already installed. Use modularity:upgrade to update it.");
    }
}
