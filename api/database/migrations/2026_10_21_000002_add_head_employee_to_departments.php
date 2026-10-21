<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who heads a department (roadmap 10.6), so an approval chain can say
 * "supervisor, then department head, then HR" (docs/APPROVAL-ENGINE-DESIGN.md,
 * Phase B). Nullable: most organisations have not named one, and a chain step
 * whose department has no head falls back to the permission holders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('head_employee_id')->nullable()->after('branch_id')
                ->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('head_employee_id');
        });
    }
};
