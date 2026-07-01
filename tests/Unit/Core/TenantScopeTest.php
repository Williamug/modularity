<?php

use Illuminate\Support\Facades\DB;
use Modularity\Core\Tenancy\TenantContext;
use Modularity\Support\Abstracts\ModuleModel;

beforeEach(function () {
    DB::statement('CREATE TABLE IF NOT EXISTS test_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tenant_id INTEGER NOT NULL,
        name TEXT
    )');
});

afterEach(function () {
    DB::statement('DROP TABLE IF EXISTS test_items');
});

it('scope adds where tenant_id when context set', function () {
    $context = app(TenantContext::class);
    $context->set(7);

    DB::enableQueryLog();

    $model = new class extends ModuleModel {
        protected $table = 'test_items';
        protected $fillable = ['tenant_id', 'name'];
        public $timestamps = false;
    };
    $model->newQuery()->get();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $context->forget();

    expect($queries)->not->toBeEmpty();
    expect($queries[0]['query'])->toContain('tenant_id');
});

it('scope skips where when context not set', function () {
    $context = app(TenantContext::class);
    $context->forget();

    DB::enableQueryLog();

    $model = new class extends ModuleModel {
        protected $table = 'test_items';
        protected $fillable = ['tenant_id', 'name'];
        public $timestamps = false;
    };
    $model->newQuery()->get();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->not->toBeEmpty();
    expect($queries[0]['query'])->not->toContain('tenant_id');
});

it('strict mode throws when querying with no tenant set', function () {
    config(['modularity.tenancy.strict' => true]);

    $context = app(TenantContext::class);
    $context->forget();

    $model = new class extends ModuleModel {
        protected $table = 'test_items';
        protected $fillable = ['tenant_id', 'name'];
        public $timestamps = false;
    };

    expect(fn () => $model->newQuery()->get())
        ->toThrow(\Modularity\Core\Tenancy\Exceptions\TenantNotResolvedException::class);
});

it('strict mode still scopes normally when a tenant is set', function () {
    config(['modularity.tenancy.strict' => true]);

    $context = app(TenantContext::class);
    $context->set(3);

    $model = new class extends ModuleModel {
        protected $table = 'test_items';
        protected $fillable = ['tenant_id', 'name'];
        public $timestamps = false;
    };

    expect(fn () => $model->newQuery()->get())->not->toThrow(\Throwable::class);

    $context->forget();
});

it('creating event sets tenant id', function () {
    $context = app(TenantContext::class);
    $context->set(42);

    DB::table('test_items')->insert(['tenant_id' => 42, 'name' => 'setup_row']);

    $model = new class extends ModuleModel {
        protected $table = 'test_items';
        protected $fillable = ['tenant_id', 'name'];
        public $timestamps = false;
    };

    $instance = $model->newInstance(['name' => 'auto-tenant']);
    $instance->save();

    $context->forget();

    expect($instance->tenant_id)->toBe(42);
});
