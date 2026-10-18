<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Trusted by" on ETHR's public site (2026-10-18). An organisation appears
 * only when it has agreed to be named (`showcase_consented_at`, by whom) and
 * the platform has not hidden it (`showcase_hidden_at`), and only while it is
 * an active customer. Naming a customer is a public claim, held to the same
 * consent the testimonial already needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('showcase_consented_at')->nullable();
            // No foreign key: users already references tenants, and a key back
            // would make the two a cycle, which the backup restore cannot order
            // (it loads tables parents first). The audit log names who agreed.
            $table->unsignedBigInteger('showcase_consented_by')->nullable();
            $table->timestamp('showcase_hidden_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['showcase_consented_at', 'showcase_consented_by', 'showcase_hidden_at']);
        });
    }
};
