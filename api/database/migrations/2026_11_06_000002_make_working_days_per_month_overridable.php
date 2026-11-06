<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The month length payroll and Art. 85 sick leave divide by is an ETHR
     * convention, not a figure the law states (`ACCOUNTANT-SIGN-OFF.md`, rows
     * 7, 9, 10), and organisations differ: 22 weekdays, or 26 days of a six-day
     * week. Until the accountant settles the default, an organisation sets its
     * own on the Payroll rules page instead of living with ours. Only the
     * flag moves; the platform value, its date and `confirmed = false` do not,
     * so no run changes until an organisation chooses.
     */
    public function up(): void
    {
        DB::table('statutory_parameters')
            ->whereNull('tenant_id')
            ->where('code', 'payroll.working_days_per_month')
            ->update(['tenant_overridable' => true]);
    }

    public function down(): void
    {
        DB::table('statutory_parameters')
            ->whereNull('tenant_id')
            ->where('code', 'payroll.working_days_per_month')
            ->update(['tenant_overridable' => false]);
    }
};
