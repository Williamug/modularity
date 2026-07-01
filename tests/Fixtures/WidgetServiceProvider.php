<?php

namespace Modularity\Tests\Fixtures;

use Modularity\Support\Abstracts\ModuleServiceProvider;

/**
 * Concrete module provider for the HTTP feature tests. It points getModulePath()
 * at the on-disk fixture module so the base class can load its routes.
 */
class WidgetServiceProvider extends ModuleServiceProvider
{
    protected string $slug = 'widget';

    protected function getModulePath(): string
    {
        return __DIR__.'/modules/widget';
    }
}
