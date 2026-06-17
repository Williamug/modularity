<?php

namespace Modularity\Core\Events;

use Modularity\Core\Events\Contracts\TenantAwareEvent;
use Modularity\Core\Module\ModuleManager;

abstract class ModuleAwareListener
{
    protected string $module;

    public function handle(object $event): void
    {
        if ($event instanceof TenantAwareEvent) {
            $manager = app(ModuleManager::class);

            if (! $manager->activeFor($this->module, $event->getTenantId())) {
                return;
            }
        }

        $this->process($event);
    }

    abstract protected function process(object $event): void;
}
