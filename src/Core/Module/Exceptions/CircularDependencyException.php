<?php

namespace Modularity\Core\Module\Exceptions;

use RuntimeException;

class CircularDependencyException extends RuntimeException
{
    public static function cycle(array $cycleSlugs): self
    {
        $path = implode(' → ', $cycleSlugs);

        return new self("Circular dependency detected: {$path}");
    }
}
