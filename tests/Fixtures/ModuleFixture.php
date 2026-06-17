<?php

namespace Modularity\Tests\Fixtures;

use Illuminate\Filesystem\Filesystem;

class ModuleFixture
{
    private string $path;

    private static int $counter = 0;

    public function __construct(
        private readonly string $slug = 'test-module',
        private readonly string $version = '1.0.0',
        private readonly array $dependencies = [],
        private readonly array $permissions = ['test-module.view', 'test-module.create'],
    ) {
        self::$counter++;
        $this->path = sys_get_temp_dir().'/modularity_test_'.self::$counter.'_'.$slug;
    }

    public function create(): static
    {
        $fs = new Filesystem();
        $fs->makeDirectory($this->path.'/database/migrations', 0755, true, true);
        $fs->makeDirectory($this->path.'/src/Providers', 0755, true, true);

        $manifest = [
            'name'          => 'Test Module',
            'slug'          => $this->slug,
            'version'       => $this->version,
            'description'   => 'A test module fixture',
            'providers'     => ['Modularity\\Tests\\Fixtures\\FakeModuleServiceProvider'],
            'permissions'   => $this->permissions,
            'dependencies'  => $this->dependencies,
            'compatibility' => '^1.0',
        ];

        $fs->put($this->path.'/module.json', json_encode($manifest, JSON_PRETTY_PRINT));

        return $this;
    }

    public function withMigration(string $tableName): static
    {
        $fs = new Filesystem();

        $migration = <<<PHP
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('{$tableName}', function (Blueprint \$table) {
            \$table->id();
            \$table->unsignedBigInteger('tenant_id')->index();
            \$table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('{$tableName}');
    }
};
PHP;

        $filename = date('Y_m_d_His')."_create_{$tableName}_table.php";
        $fs->put($this->path.'/database/migrations/'.$filename, $migration);

        return $this;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function cleanup(): void
    {
        (new Filesystem())->deleteDirectory($this->path);
    }
}
