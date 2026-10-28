<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform feature control center's state (docs/FEATURE-CONTROL-CENTER-DESIGN.md).
 *
 * `platform_feature_states` holds one row per feature whose platform state
 * differs from the catalogue default; `tenant_feature_overrides` one grant or
 * revoke per organisation and feature. Both start empty, so every answer equals
 * the plan gate's until the platform admin changes something.
 *
 * They replace `feature_flags`, which nothing ever read or wrote: it could hold
 * only on or off, with no reason, expiry or actor. Nothing proves nothing wrote
 * to it, so the drop refuses to run on a table holding rows rather than
 * deleting them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('feature_flags') && DB::table('feature_flags')->exists()) {
            throw new RuntimeException(
                'feature_flags holds rows. Nothing in the application reads it, but they were written by '
                .'something: export them and empty the table before migrating.',
            );
        }

        Schema::create('platform_feature_states', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('state', 16);
            $table->string('reason', 500)->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('tenant_feature_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('mode', 16);
            $table->string('reason', 500);
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'key'], 'tfo_tenant_key_uq');
        });

        Schema::dropIfExists('feature_flags');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_feature_overrides');
        Schema::dropIfExists('platform_feature_states');

        Schema::create('feature_flags', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('key');
            $table->boolean('enabled')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
            $table->index('key');

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }
};
