<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Session metadata for the active-sessions list (PHASE_00 S03).
 *
 * Sanctum tokens already *are* the sessions — what they lack is any way to tell
 * one apart from another in a UI. `last_used_at` exists; where the session was
 * created from does not. Without these columns "Active sessions" could only ever
 * render an indistinguishable list of "auth" rows, which is not a security
 * control anyone can act on.
 *
 * All columns are nullable: tokens issued before this migration keep working and
 * simply render as an unknown device rather than breaking the list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable()->after('last_used_at');
            $table->text('user_agent')->nullable()->after('ip_address');
            // Stable per-device value so the same browser reuses one session row
            // and a trusted-device record can be correlated with it.
            $table->string('device_hash', 64)->nullable()->after('user_agent');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'user_agent', 'device_hash']);
        });
    }
};
