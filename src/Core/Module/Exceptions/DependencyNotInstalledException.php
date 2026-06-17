<?php

namespace Modularity\Core\Module\Exceptions;

use RuntimeException;

class DependencyNotInstalledException extends RuntimeException
{
    public static function missing(string $moduleSlug, string $depSlug): self
    {
        return new self("Module [{$moduleSlug}] depends on [{$depSlug}] which is not installed. Install [{$depSlug}] first.");
    }
}
