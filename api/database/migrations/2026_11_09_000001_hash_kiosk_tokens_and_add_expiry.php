<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A kiosk token is a credential, and was kept as written: anyone who could
     * read `kiosk_sessions` could punch for the whole organisation, for ever
     * (review 2026-11-09). The column now holds the SHA-256 of the token, as
     * `api_keys.key_hash` does, and each session expires.
     *
     * Existing tokens are hashed in place, so a terminal already in service
     * keeps working with the token it holds. Each existing session is given
     * the full lifetime from today rather than from when it was registered,
     * so no terminal stops at the moment this deploys.
     *
     * Not reversible in substance: `down()` restores the column's name, but
     * the hashes cannot be turned back into tokens, so every kiosk must be
     * given a new one after a rollback.
     */
    public function up(): void
    {
        Schema::table('kiosk_sessions', function (Blueprint $table): void {
            $table->renameColumn('token', 'token_hash');
        });

        Schema::table('kiosk_sessions', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable();
        });

        $expires = now()->addDays((int) config('attendance.kiosk_session_days', 90));

        DB::table('kiosk_sessions')->orderBy('id')->select(['id', 'token_hash'])->each(function (object $row) use ($expires): void {
            DB::table('kiosk_sessions')->where('id', $row->id)->update([
                'token_hash' => hash('sha256', (string) $row->token_hash),
                'expires_at' => $expires,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('kiosk_sessions', function (Blueprint $table): void {
            $table->dropColumn('expires_at');
        });

        Schema::table('kiosk_sessions', function (Blueprint $table): void {
            $table->renameColumn('token_hash', 'token');
        });
    }
};
