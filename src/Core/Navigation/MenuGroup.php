<?php

namespace Modularity\Core\Navigation;

use Illuminate\Support\Collection;

class MenuGroup
{
    /** @var MenuItem[] */
    private array $items = [];

    public function __construct(
        public readonly string $label,
        public readonly string $icon = '',
        public readonly int $order = 100,
    ) {}

    public function addItem(MenuItem $item): void
    {
        $this->items[] = $item;
    }

    public function items(): Collection
    {
        return collect($this->items)->sortBy('order')->values();
    }
}
