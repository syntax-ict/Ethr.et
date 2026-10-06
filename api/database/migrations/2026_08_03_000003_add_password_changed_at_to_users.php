<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks when a password was last set, so the tenant-configurable expiry in
 * `PasswordPolicy` can actually be enforced (PHASE_00 S03).
 *
 * The policy could already *compute* expiry, but nothing recorded the one input
 * it needs, so the setting could never take effect.
 *
 * Nullable on purpose: existing accounts have no recorded change date, and
 * `PasswordPolicy::isExpired()` treats null as "not expired". Backfilling with
 * `now()` would be a lie about when those passwords were set; backfilling with
 * the account's creation date would instantly expire long-lived accounts the
 * moment a tenant enables the policy. Null means "unknown, don't force a change"
 * — the clock starts at each account's next password change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('password_changed_at')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password_changed_at');
        });
    }
};
