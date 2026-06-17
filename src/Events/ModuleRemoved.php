<?php

namespace Modularity\Events;

class ModuleRemoved
{
    public function __construct(public readonly string $slug) {}
}
