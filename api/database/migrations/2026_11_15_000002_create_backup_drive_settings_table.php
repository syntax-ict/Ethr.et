<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Drive as the fallback destination for off-host backups, connected
 * from the platform console with the owner's own OAuth client.
 *
 * One global row, no `tenant_id`, for the reason `backup_storage_settings`
 * gives: the backup is of the whole platform. The client secret, the refresh
 * token and the archive key are encrypted columns and no API returns them.
 * The archive key is this destination's own, so a copy on Drive is opened with
 * the key shown for Drive, whatever the primary storage uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_drive_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('client_id', 255);
            $table->text('client_secret');
            $table->text('refresh_token')->nullable();
            $table->string('account_email', 255)->nullable();
            $table->string('folder_id', 191)->nullable();
            $table->text('encryption_key');
            $table->timestamp('key_revealed_at')->nullable();
            $table->timestamp('key_saved_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_test_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->string('last_test_error', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_drive_settings');
    }
};
