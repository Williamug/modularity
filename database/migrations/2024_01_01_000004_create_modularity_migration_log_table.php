<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modularity_migration_log', function (Blueprint $table) {
            $table->id();
            $table->string('module_slug')->index();
            $table->string('migration_file');
            $table->unsignedInteger('batch');
            $table->timestamp('ran_at')->useCurrent();

            $table->unique(['module_slug', 'migration_file']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modularity_migration_log');
    }
};
