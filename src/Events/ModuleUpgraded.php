<?php

namespace Modularity\Events;

class ModuleUpgraded
{
    public function __construct(
        public readonly string $slug,
        public readonly string $oldVersion,
        public readonly string $newVersion,
    ) {}
}
