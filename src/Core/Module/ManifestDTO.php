<?php

namespace Modularity\Core\Module;

class ManifestDTO
{
    public function __construct(
        public readonly string $name,
        public readonly string $slug,
        public readonly string $version,
        public readonly string $description,
        public readonly array $providers,
        public readonly array $permissions,
        public readonly array $dependencies,
        public readonly string $compatibility,
        public readonly string $path,
    ) {}

    public function hasDependencies(): bool
    {
        return ! empty($this->dependencies);
    }

    public function dependencySlugs(): array
    {
        return array_column($this->dependencies, 'slug');
    }
}
