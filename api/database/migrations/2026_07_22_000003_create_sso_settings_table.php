<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_settings', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20)->default('saml');
            $table->boolean('is_enabled')->default(false);
            $table->string('idp_entity_id')->nullable();
            $table->string('idp_sso_url', 2048)->nullable();
            $table->string('idp_slo_url', 2048)->nullable();
            $table->text('idp_certificate')->nullable();
            $table->string('default_role', 30)->default('employee');
            $table->boolean('auto_provision')->default(false);
            $table->json('attribute_mapping')->nullable();
            $table->timestamps();

            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_settings');
    }
};
