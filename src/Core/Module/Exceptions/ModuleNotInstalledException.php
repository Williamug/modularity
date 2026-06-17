<?php

namespace Modularity\Core\Module\Exceptions;

use RuntimeException;

class ModuleNotInstalledException extends RuntimeException
{
    public static function slug(string $slug): self
    {
        return new self("Module [{$slug}] is discovered but not installed. Run: php artisan modularity:install {$slug}");
    }
}
