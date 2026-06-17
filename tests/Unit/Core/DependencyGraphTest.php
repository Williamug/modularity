<?php

use Modularity\Core\Module\DependencyGraph;
use Modularity\Core\Module\Exceptions\CircularDependencyException;
use Modularity\Core\Module\ManifestDTO;

beforeEach(function () {
    $this->make = fn (string $slug, array $deps = []) => new ManifestDTO(
        name:          ucfirst($slug),
        slug:          $slug,
        version:       '1.0.0',
        description:   '',
        providers:     [],
        permissions:   [],
        dependencies:  array_map(fn ($d) => ['slug' => $d], $deps),
        compatibility: '*',
        path:          '/tmp/'.$slug,
    );
});

it('resolves simple linear chain', function () {
    $a = ($this->make)('module-a');
    $b = ($this->make)('module-b', ['module-a']);
    $c = ($this->make)('module-c', ['module-b']);

    $sorted = (new DependencyGraph([$c, $b, $a]))->resolve();
    $slugs  = array_map(fn ($m) => $m->slug, $sorted);

    expect($slugs)->toBe(['module-a', 'module-b', 'module-c']);
});

it('resolves branching dependencies', function () {
    $core    = ($this->make)('core');
    $finance = ($this->make)('finance', ['core']);
    $hr      = ($this->make)('hr', ['core']);
    $payroll = ($this->make)('payroll', ['finance', 'hr']);

    $sorted = (new DependencyGraph([$payroll, $hr, $finance, $core]))->resolve();
    $slugs  = array_map(fn ($m) => $m->slug, $sorted);

    expect(array_search('core', $slugs))->toBeLessThan(array_search('finance', $slugs));
    expect(array_search('core', $slugs))->toBeLessThan(array_search('hr', $slugs));
    expect(array_search('finance', $slugs))->toBeLessThan(array_search('payroll', $slugs));
    expect(array_search('hr', $slugs))->toBeLessThan(array_search('payroll', $slugs));
});

it('detects circular dependency', function () {
    $a = ($this->make)('module-a', ['module-b']);
    $b = ($this->make)('module-b', ['module-a']);

    expect(fn () => (new DependencyGraph([$a, $b]))->resolve())
        ->toThrow(CircularDependencyException::class);
});

it('handles single module with no deps', function () {
    $a = ($this->make)('standalone');

    $sorted = (new DependencyGraph([$a]))->resolve();

    expect($sorted)->toHaveCount(1);
    expect($sorted[0]->slug)->toBe('standalone');
});

it('skips edges for unknown dependencies', function () {
    $a = ($this->make)('module-a', ['external-missing']);

    $sorted = (new DependencyGraph([$a]))->resolve();

    expect($sorted)->toHaveCount(1);
});
