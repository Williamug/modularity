<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modularity_tenant_module_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('module_slug');
            $table->enum('status', ['active', 'trial', 'expired', 'free'])->default('free');
            $table->string('billing_cycle')->default('free'); // monthly|yearly|lifetime|free
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'module_slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modularity_tenant_module_subscriptions');
    }
};
