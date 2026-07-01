<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance indexes for high-traffic queries.
 * Added in Phase 9 after identifying hot paths from query analysis.
 */
return new class extends Migration
{
    public function up(): void
    {
        // attendance_records: team attendance today query (tenant_id + date)
        $this->safeIndex('attendance_records', ['tenant_id', 'date'], 'att_tenant_date');

        // attendance_records: employee's attendance history
        $this->safeIndex('attendance_records', ['employee_id', 'date'], 'att_emp_date');

        // leave_requests: pending approvals by supervisor
        $this->safeIndex('leave_requests', ['employee_id', 'status'], 'leave_emp_status');
        $this->safeIndex('leave_requests', ['tenant_id', 'status'], 'leave_tenant_status');

        // leave_requests: calendar range queries
        $this->safeIndex('leave_requests', ['start_date', 'end_date'], 'leave_date_range');

        // employees: search by name/code within tenant
        $this->safeIndex('employees', ['tenant_id', 'status'], 'emp_tenant_status');

        // audit_log: recent activity lookup
        $this->safeIndex('audit_log', ['tenant_id', 'created_at'], 'audit_tenant_created');
        $this->safeIndex('audit_log', ['action', 'created_at'], 'audit_action_created');

        // notifications: unread count per user
        $this->safeIndex('notifications', ['notifiable_id', 'read_at'], 'notif_user_read');

        // payroll_entries: per-run lookup
        $this->safeIndex('payroll_entries', ['payroll_run_id', 'employee_id'], 'pay_run_emp');

        // webhook_deliveries: delivery log per webhook
        $this->safeIndex('webhook_deliveries', ['webhook_id', 'created_at'], 'wh_del_webhook_created');
    }

    public function down(): void
    {
        $indexes = [
            ['attendance_records', 'att_tenant_date'],
            ['attendance_records', 'att_emp_date'],
            ['leave_requests', 'leave_emp_status'],
            ['leave_requests', 'leave_tenant_status'],
            ['leave_requests', 'leave_date_range'],
            ['employees', 'emp_tenant_status'],
            ['audit_log', 'audit_tenant_created'],
            ['audit_log', 'audit_action_created'],
            ['notifications', 'notif_user_read'],
            ['payroll_entries', 'pay_run_emp'],
            ['webhook_deliveries', 'wh_del_webhook_created'],
        ];

        foreach ($indexes as [$table, $name]) {
            if (Schema::hasTable($table)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndexIfExists($name));
            }
        }
    }

    private function safeIndex(string $table, array $columns, string $name): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $t) use ($columns, $name, $table) {
            // Skip if all columns exist; skip if index already exists
            $existing = \Illuminate\Support\Facades\DB::select(
                "SHOW INDEX FROM `{$table}` WHERE Key_name = ?",
                [$name]
            );
            if (empty($existing)) {
                $t->index($columns, $name);
            }
        });
    }
};
