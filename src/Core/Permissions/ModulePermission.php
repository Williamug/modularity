<?php

namespace Modularity\Core\Permissions;

class ModulePermission
{
    public function __construct(
        public readonly string $moduleSlug,
        public readonly string $name,
        public readonly string $label = '',
        public readonly string $description = '',
    ) {}

    public function fullName(): string
    {
        return $this->name;
    }
}
