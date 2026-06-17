<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modularity_installed_modules', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('version');
            $table->string('checksum')->nullable();
            $table->enum('status', ['installed', 'errored'])->default('installed');
            $table->timestamp('installed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modularity_installed_modules');
    }
};
