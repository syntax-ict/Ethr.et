<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attendance integrity: every punch is inspected as it is recorded, and
     * what cannot be trusted on its face - a location outside the branch, a
     * coordinate repeated to the last decimal, a clock that ran backwards, a
     * kiosk at another branch - is written here for a person to decide. A
     * rejected flag voids the punch, so nothing questionable reaches payroll
     * without someone having looked at it.
     *
     * `devices.timezone`: the zone a device's clock is set to. Most
     * time-and-attendance terminals report wall-clock times with no offset;
     * read as UTC they land three hours late in Addis Ababa. Null means the
     * organisation's zone.
     */
    public function up(): void
    {
        Schema::create('attendance_integrity_flags', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('punch', 10);
            $table->string('type', 40);
            $table->string('severity', 10);
            $table->json('details')->nullable();
            $table->string('status', 10)->default('open');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();

            // Named: the generated names pass MariaDB's 64-character limit.
            $table->unique(['attendance_record_id', 'punch', 'type'], 'integrity_flags_record_punch_type_unique');
            $table->index(['tenant_id', 'status', 'severity'], 'integrity_flags_tenant_status_severity_index');
        });

        Schema::table('devices', function (Blueprint $table): void {
            $table->string('timezone', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });

        Schema::dropIfExists('attendance_integrity_flags');
    }
};
