<?php

namespace Modularity\Core\Module\Exceptions;

use RuntimeException;

class InvalidManifestException extends RuntimeException
{
    public static function missingField(string $field, string $path): self
    {
        return new self("Module manifest at [{$path}] is missing required field: [{$field}].");
    }

    public static function invalidSlug(string $slug, string $path): self
    {
        return new self("Module manifest at [{$path}] has an invalid slug [{$slug}]. Slugs must be lowercase alphanumeric with hyphens only.");
    }

    public static function invalidJson(string $path): self
    {
        return new self("Module manifest at [{$path}] contains invalid JSON.");
    }
}
