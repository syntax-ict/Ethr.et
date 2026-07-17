<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->after('period_end');
            $table->unique(['tenant_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
