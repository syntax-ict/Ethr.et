<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ethiopian higher-education cost sharing.
 *
 * Graduates of public universities repay a share of the cost of their education,
 * and the employer withholds it from payroll and remits it. It is modelled as an
 * amortising obligation rather than an open-ended percentage so that the balance
 * is always answerable ("how much does this employee still owe?") and so the
 * deduction stops on its own when the debt is cleared — the same shape as
 * `employee_loans`, which is the closest existing analogue.
 *
 * `deduction_rate_percent` is per-row and required rather than a hard-coded
 * constant. The commonly-cited figure is 10% of salary, but the rate and the
 * total obligation both come from the graduate's individual agreement, and the
 * governing proclamation has been amended more than once. Storing the rate with
 * the obligation means a rate change is data, not a deploy — the same reason
 * `tax_brackets` is a table and not a PHP array.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_cost_sharing', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            // Integer minor units (CLAUDE.md convention #3). Never FLOAT/DECIMAL.
            $table->bigInteger('total_obligation_cents');
            $table->bigInteger('outstanding_cents');

            // Basis points would be overkill; percent with 2dp covers every
            // published rate while staying readable in the calculation_log.
            $table->decimal('deduction_rate_percent', 5, 2);

            $table->string('status', 20)->default('active');
            $table->date('started_on');
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id']);

            // Payroll resolves "the active obligation for this employee" on every
            // run, for every employee; without this it is a scan per employee.
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_cost_sharing');
    }
};
