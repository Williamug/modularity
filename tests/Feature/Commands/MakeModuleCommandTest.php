<?php

use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $this->tmpModulesPath = sys_get_temp_dir().'/modularity_test_modules_'.uniqid();
    mkdir($this->tmpModulesPath, 0755, true);
    config(['modularity.modules_path' => $this->tmpModulesPath]);
});

afterEach(function () {
    (new Filesystem())->deleteDirectory($this->tmpModulesPath);
});

it('scaffolds module directory tree', function () {
    $this->artisan('module:make-module', ['name' => 'Inventory'])
        ->assertExitCode(0);

    $modulePath = $this->tmpModulesPath.'/Inventory';

    expect($modulePath)->toBeDirectory();
    expect($modulePath.'/module.json')->toBeFile();
    expect($modulePath.'/routes/web.php')->toBeFile();
    expect($modulePath.'/routes/api.php')->toBeFile();
    expect($modulePath.'/src/Providers/InventoryServiceProvider.php')->toBeFile();
    expect($modulePath.'/src/Models/Inventory.php')->toBeFile();
    expect($modulePath.'/resources/views/index.blade.php')->toBeFile();
});

it('module json has correct slug', function () {
    $this->artisan('module:make-module', ['name' => 'PointOfSale'])
        ->assertExitCode(0);

    $manifest = json_decode(
        file_get_contents($this->tmpModulesPath.'/PointOfSale/module.json'),
        true
    );

    expect($manifest['slug'])->toBe('point-of-sale');
    expect($manifest['name'])->toBe('PointOfSale');
});

it('tokens are replaced in service provider', function () {
    $this->artisan('module:make-module', ['name' => 'Library'])
        ->assertExitCode(0);

    $provider = file_get_contents(
        $this->tmpModulesPath.'/Library/src/Providers/LibraryServiceProvider.php'
    );

    expect($provider)->toContain("'library'");
    expect($provider)->not->toContain('{{PascalName}}');
    expect($provider)->not->toContain('{{kebab-slug}}');
});

it('fails if module already exists', function () {
    mkdir($this->tmpModulesPath.'/Payroll', 0755, true);

    $this->artisan('module:make-module', ['name' => 'Payroll'])
        ->assertExitCode(1);
});
