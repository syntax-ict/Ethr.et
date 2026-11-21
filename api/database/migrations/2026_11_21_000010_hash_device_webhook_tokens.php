<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Device webhook tokens stored hashed (biometric upgrade, PR 3).
 *
 * `devices.webhook_token` held the credential in clear, and database backups
 * go off-host, so a copy of any backup could post punches into any
 * organisation. The token is now kept as a plain sha256 (not a keyed hash:
 * APP_KEY must never be regenerated, and a keyed hash would chain every token
 * to it). Tokens stay 64 hex characters, so no terminal's address changes.
 *
 *  - webhook_token_hash: sha256 of the token; the lookup key.
 *  - webhook_token_prefix: its first 8 characters, for display only.
 *  - webhook_token_head_hash: sha256 of its first 16 characters, so a terminal
 *    that sent a cut-off token can still be traced (WebhookRejections).
 *  - webhook_token_previous_hash and its expiry: the token a "moving"
 *    regeneration keeps alive for 24 hours. Unique, like the current hash,
 *    because the lookup is cross-tenant.
 *
 * The backfill is in PHP, not SQL SHA2(), so SQLite runs it. It is chunked and
 * re-runnable: it only touches rows that still hold plaintext and no hash. A
 * backfilled row has its plaintext set to NULL. The `webhook_token` column and
 * its unique index stay; a later release drops them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->char('webhook_token_hash', 64)->nullable()->unique();
            $table->char('webhook_token_prefix', 8)->nullable();
            $table->char('webhook_token_head_hash', 64)->nullable()->index();
            $table->char('webhook_token_previous_hash', 64)->nullable()->unique();
            $table->timestamp('webhook_token_previous_expires_at')->nullable();
        });

        DB::table('devices')
            ->whereNotNull('webhook_token')
            ->where('webhook_token', '!=', '')
            ->whereNull('webhook_token_hash')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $token = (string) $row->webhook_token;

                    DB::table('devices')->where('id', $row->id)->update([
                        'webhook_token_hash' => hash('sha256', $token),
                        'webhook_token_prefix' => substr($token, 0, 8),
                        'webhook_token_head_hash' => hash('sha256', substr($token, 0, 16)),
                        'webhook_token' => null,
                    ]);
                }
            });
    }

    /**
     * The plaintext cannot be restored: only its hash was kept. Rolling back
     * drops the new columns and leaves every backfilled device with no token
     * at all, so a terminal is refused until it is given a new one. The
     * pre-migrate backup the release steps take is the only way back to the
     * old tokens.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropUnique(['webhook_token_hash']);
            $table->dropUnique(['webhook_token_previous_hash']);
            $table->dropIndex(['webhook_token_head_hash']);
        });

        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn([
                'webhook_token_hash',
                'webhook_token_prefix',
                'webhook_token_head_hash',
                'webhook_token_previous_hash',
                'webhook_token_previous_expires_at',
            ]);
        });
    }
};
