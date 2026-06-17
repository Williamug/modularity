<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modularity_tenant_modules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('module_slug');
            $table->boolean('active')->default(false);
            $table->json('settings')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();

            $table->unique(['tenant_id', 'module_slug']);
            $table->index('module_slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modularity_tenant_modules');
    }
};
