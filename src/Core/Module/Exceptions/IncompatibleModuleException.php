<?php

namespace Modularity\Core\Module\Exceptions;

use RuntimeException;

class IncompatibleModuleException extends RuntimeException
{
    public static function version(string $slug, string $constraint, string $platformVersion): self
    {
        return new self(
            "Module [{$slug}] requires platform version [{$constraint}] but the installed version is [{$platformVersion}]."
        );
    }
}
