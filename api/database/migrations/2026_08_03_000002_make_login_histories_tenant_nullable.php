<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow tenant-less login history rows.
 *
 * Platform super admins have `tenant_id = null` by design (CLAUDE.md's global
 * model list), so recording their sign-ins against a NOT NULL `tenant_id` threw
 * an integrity-constraint violation and turned super-admin login into a 500.
 *
 * `audit_log.tenant_id` is already nullable for exactly this reason; this brings
 * `login_histories` into line. Super-admin sign-ins are the ones most worth
 * having in a login history, so dropping them instead of recording them would be
 * the wrong trade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_histories', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('login_histories', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable(false)->change();
        });
    }
};
