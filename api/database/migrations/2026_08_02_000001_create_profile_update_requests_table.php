<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee-initiated changes to approval-gated profile fields (PHASE_05 S24).
 *
 * `ProfileController::update()` already told the employee that changes to `name`,
 * `bank_name` and `bank_account_number` were "pending HR approval" — but the only
 * thing it did with them was write an audit line and drop the values. Nothing was
 * persisted, nothing reached the approvals centre, and no reviewer could ever act
 * on the request. This table is where those values now live until a reviewer with
 * `employee.update` accepts or rejects them.
 *
 * One row per field, not one per submission: HR approves "the new bank account"
 * without being forced to also accept "the new legal name" in the same decision.
 *
 * `old_value` / `new_value` are encrypted at rest because the set of gated fields
 * is exactly the sensitive set — bank account numbers and TINs — and a plaintext
 * staging table would defeat the `encrypted` cast on `employee_bank_details`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_update_requests', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            // Who submitted it. Usually the employee's own user, but kept separate
            // from employee_id so an HR-on-behalf-of submission stays attributable.
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('field_name', 64);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();

            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            $table->timestamps();

            // The approvals centre lists pending rows per tenant; the employee's own
            // profile page lists their own rows regardless of status.
            $table->index(['tenant_id', 'status']);
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_update_requests');
    }
};
