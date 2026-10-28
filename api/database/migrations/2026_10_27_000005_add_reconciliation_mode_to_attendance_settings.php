<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How firmly attendance reconciliation stands between a pay period's
 * attendance and its payroll run (App\Services\Payroll\AttendanceReconciliation).
 *
 *  - `off`: the run is approved as it always was. The default, so no
 *    organisation sees a change until it chooses one.
 *  - `advisory`: the run page lists what is still open.
 *  - `required`: a run is not approved while an exception in its period is
 *    open or pending, or once a decision was made after it was processed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table): void {
            $table->string('reconciliation_mode', 10)->default('off')->after('lateness_deduction_basis');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table): void {
            $table->dropColumn('reconciliation_mode');
        });
    }
};
