<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide settings owned by the super admin, not by any tenant.
 *
 * Currently the bank account tenants pay ETHR into. That lived as literal JSX in
 * the billing page — a placeholder account number ("1000 1234 5678 90") shipped in
 * the browser bundle, with the bank *name* stored as a translation key, so the
 * payment destination could differ between the English and Amharic UI.
 *
 * Deliberately global: there is no `tenant_id` and no `BelongsToTenant`, because
 * every tenant reads the same account. `PlatformSetting` is registered in
 * TenantIsolationTest's GLOBAL_MODELS allow-list, which is what keeps that an
 * explicit decision rather than an oversight.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            // Nullable so the row can exist before an operator has filled it in.
            // The billing page treats "not configured yet" as a distinct state
            // rather than rendering a blank or, worse, a plausible-looking default.
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->text('payment_instructions')->nullable();
            $table->text('payment_instructions_am')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
