<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a change to someone's pay, dated inside a month already released, would
 * have changed in that month's payslip.
 *
 * A row belongs to one released run and one employee and says whether they were
 * underpaid or overpaid, in gross and in net. No money moves from it: finance
 * settles or dismisses it, and the next run's approval asks them to say they
 * have seen the open ones (App\Services\Payroll\PayDifferenceRecorder).
 *
 * One live row (pending or open) per run and employee is kept in code, under a
 * row lock, not by an index: MariaDB has no partial index.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payroll_pay_differences')) {
            return;
        }

        Schema::create('payroll_pay_differences', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_entry_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12)->nullable();
            $table->bigInteger('gross_cents')->nullable();
            $table->bigInteger('net_cents')->nullable();
            $table->bigInteger('paid_gross_cents')->nullable();
            $table->bigInteger('paid_net_cents')->nullable();
            $table->bigInteger('due_gross_cents')->nullable();
            $table->bigInteger('due_net_cents')->nullable();
            $table->json('detail')->nullable();
            $table->string('status', 12)->default('pending');
            $table->date('changed_from');
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'employee_id'], 'pay_diff_tenant_status_employee_index');
            $table->index(['tenant_id', 'payroll_run_id', 'employee_id'], 'pay_diff_tenant_run_employee_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_pay_differences');
    }
};
