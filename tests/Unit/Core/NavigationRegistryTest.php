<?php

use Modularity\Core\Module\ModuleManager;
use Modularity\Core\Navigation\NavigationRegistry;

it('adds and retrieves items', function () {
    $registry = new NavigationRegistry();

    $registry->add([
        'module' => 'library',
        'label'  => 'Library',
        'route'  => 'library.index',
    ]);

    expect($registry->all())->toHaveCount(1);
});

it('for tenant filters inactive modules', function () {
    $manager = Mockery::mock(ModuleManager::class);
    $manager->shouldReceive('activeFor')->with('library', 1)->andReturn(false);
    $manager->shouldReceive('activeFor')->with('finance', 1)->andReturn(true);

    $this->app->instance(ModuleManager::class, $manager);
    $this->app->instance('modularity.manager', $manager);

    $registry = new NavigationRegistry();
    $registry->add(['module' => 'library', 'label' => 'Library', 'route' => 'library.index']);
    $registry->add(['module' => 'finance', 'label' => 'Finance', 'route' => 'finance.index']);

    $items = $registry->forTenant(1);

    expect($items)->toHaveCount(1);
    expect($items->first()->module)->toBe('finance');
});

it('items are sorted by order', function () {
    $manager = Mockery::mock(ModuleManager::class);
    $manager->shouldReceive('activeFor')->andReturn(true);

    $this->app->instance(ModuleManager::class, $manager);
    $this->app->instance('modularity.manager', $manager);

    $registry = new NavigationRegistry();
    $registry->add(['module' => 'b', 'label' => 'B', 'route' => 'b.index', 'order' => 200]);
    $registry->add(['module' => 'a', 'label' => 'A', 'route' => 'a.index', 'order' => 50]);

    $items = $registry->forTenant(1);

    expect($items->first()->module)->toBe('a');
    expect($items->last()->module)->toBe('b');
});
