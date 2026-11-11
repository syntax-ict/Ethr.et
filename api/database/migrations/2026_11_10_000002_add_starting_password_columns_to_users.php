<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A starting password HR gives a batch of employees (owner decision
 * 2026-11-10, docs/decisions/OWNER-DECISION-MASS-ACTIVATION.md).
 *
 * `password_change_required_at` holds the account at the change-password
 * screen until the person chooses their own password.
 * `starting_password_expires_at` is when an unused starting password stops
 * working and the account goes back to `invited`.
 *
 * Both nullable with no backfill: the migration runs over HTTP inside the
 * host's 30 seconds, and MariaDB DDL is not transactional. Each column is
 * guarded on its own so a run that stopped between them can be run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'password_change_required_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('password_change_required_at')->nullable();
            });
        }

        if (! Schema::hasColumn('users', 'starting_password_expires_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('starting_password_expires_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['starting_password_expires_at', 'password_change_required_at'] as $column) {
            if (Schema::hasColumn('users', $column)) {
                Schema::table('users', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
