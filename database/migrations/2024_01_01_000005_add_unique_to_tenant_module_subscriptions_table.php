<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modularity_tenant_module_subscriptions', function (Blueprint $table) {
            $table->unique(['tenant_id', 'module_slug'], 'modularity_subs_tenant_module_unique');
        });
    }

    public function down(): void
    {
        Schema::table('modularity_tenant_module_subscriptions', function (Blueprint $table) {
            $table->dropUnique('modularity_subs_tenant_module_unique');
        });
    }
};
