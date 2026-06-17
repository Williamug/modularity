<?php

use Modularity\Core\Module\Exceptions\InvalidManifestException;
use Modularity\Core\Module\ManifestDTO;
use Modularity\Core\Module\ModuleManifest;

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir().'/modularity_manifest_test_'.uniqid();
    mkdir($this->tmpDir, 0755, true);
    $this->write = fn (array $data) => file_put_contents($this->tmpDir.'/module.json', json_encode($data));
});

afterEach(function () {
    array_map('unlink', glob($this->tmpDir.'/*'));
    rmdir($this->tmpDir);
});

it('parses valid manifest', function () {
    ($this->write)([
        'name'        => 'Library',
        'slug'        => 'library',
        'version'     => '1.0.0',
        'description' => 'Library module',
        'providers'   => ['Modules\\Library\\LibraryServiceProvider'],
        'permissions' => ['library.view', 'library.create'],
    ]);

    $dto = ModuleManifest::parse($this->tmpDir);

    expect($dto)->toBeInstanceOf(ManifestDTO::class);
    expect($dto->slug)->toBe('library');
    expect($dto->name)->toBe('Library');
    expect($dto->version)->toBe('1.0.0');
    expect($dto->permissions)->toBe(['library.view', 'library.create']);
    expect($dto->path)->toBe($this->tmpDir);
});

it('throws on missing required field', function () {
    ($this->write)([
        'slug'      => 'library',
        'version'   => '1.0.0',
        'providers' => [],
        // 'name' is missing
    ]);

    expect(fn () => ModuleManifest::parse($this->tmpDir))
        ->toThrow(InvalidManifestException::class, 'missing required field');
});

it('throws on invalid slug', function () {
    ($this->write)([
        'name'      => 'Library',
        'slug'      => 'Library Module', // spaces + uppercase not allowed
        'version'   => '1.0.0',
        'providers' => ['Modules\\Library\\LibraryServiceProvider'],
    ]);

    expect(fn () => ModuleManifest::parse($this->tmpDir))
        ->toThrow(InvalidManifestException::class, 'invalid slug');
});

it('throws on invalid json', function () {
    file_put_contents($this->tmpDir.'/module.json', '{invalid json}');

    expect(fn () => ModuleManifest::parse($this->tmpDir))
        ->toThrow(InvalidManifestException::class, 'invalid JSON');
});

it('optional fields default correctly', function () {
    ($this->write)([
        'name'      => 'Library',
        'slug'      => 'library',
        'version'   => '1.0.0',
        'providers' => ['Modules\\Library\\LibraryServiceProvider'],
    ]);

    $dto = ModuleManifest::parse($this->tmpDir);

    expect($dto->permissions)->toBe([]);
    expect($dto->dependencies)->toBe([]);
    expect($dto->compatibility)->toBe('*');
    expect($dto->description)->toBe('');
});
