<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Overtime asked for before it is worked (blueprint S8, AT10). An employee,
 * or HR on their behalf, asks to work up to so many minutes of overtime on a
 * day; the request is decided through the approval engine like leave.
 *
 * `overtime_requires_approval` is the organisation's switch. Off, nothing
 * changes. On, payroll flags overtime worked beyond what was approved for
 * the day. It never withholds pay: overtime worked is owed (Proclamation
 * 1156/2019 Art. 68).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_requests', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedSmallInteger('planned_minutes');
            $table->string('reason', 1000);
            $table->string('status', 20)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 1000)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'overtime_requests_tenant_status');
            $table->index(['employee_id', 'date'], 'overtime_requests_employee_date');
        });

        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->boolean('overtime_requires_approval')->default(false)->after('ot_daily_cap_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn('overtime_requires_approval');
        });

        Schema::dropIfExists('overtime_requests');
    }
};
