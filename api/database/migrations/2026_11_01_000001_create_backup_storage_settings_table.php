<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Off-host backup storage configured from the platform console instead of
 * `.env`, so the owner can switch it on the day storage is bought, with no
 * deploy and no file edit on the host.
 *
 * One global row, no `tenant_id`: the backup is of the whole platform. The
 * storage secret and the archive encryption key are encrypted columns and no
 * API returns the secret; the key is shown only through its own audited
 * action, because the operator must keep a copy away from the host.
 *
 * Its own table rather than columns on `platform_settings`, for the reason
 * `sms_gateways` gives: that row feeds the public site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_storage_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('endpoint', 500);
            $table->string('region', 64)->default('us-east-1');
            $table->string('bucket', 191);
            $table->string('access_key_id', 191);
            $table->text('secret_access_key');
            $table->boolean('path_style')->default(true);
            $table->text('encryption_key');
            $table->timestamp('key_revealed_at')->nullable();
            $table->timestamp('key_saved_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_test_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->string('last_test_error', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_storage_settings');
    }
};
