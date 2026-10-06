<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a national ID to employees for identity matching (ONBOARDING_V2.md D4).
 *
 * National ID is PII, so the value is stored encrypted (`national_id`) and is
 * therefore not queryable. Matching uses a **blind index**: a deterministic
 * HMAC-SHA256 of the normalized value (`national_id_hash`) that supports exact
 * equality lookups without exposing or decrypting the number. The model keeps
 * the two columns in sync on save.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->text('national_id')->nullable()->after('nationality');
            $table->char('national_id_hash', 64)->nullable()->after('national_id');

            $table->index(['tenant_id', 'national_id_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'national_id_hash']);
            $table->dropColumn(['national_id', 'national_id_hash']);
        });
    }
};
