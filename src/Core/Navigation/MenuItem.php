<?php

namespace Modularity\Core\Navigation;

class MenuItem
{
    public function __construct(
        public readonly string $module,
        public readonly string $label,
        public readonly string $route,
        public readonly string $icon = '',
        public readonly string $permission = '',
        public readonly int $order = 100,
        public readonly string $group = 'general',
        public readonly array $children = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            module:     $data['module'],
            label:      $data['label'],
            route:      $data['route'],
            icon:       $data['icon'] ?? '',
            permission: $data['permission'] ?? '',
            order:      $data['order'] ?? 100,
            group:      $data['group'] ?? 'general',
            children:   $data['children'] ?? [],
        );
    }
}
