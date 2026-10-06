<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->foreignId('tenant_id')->after('id')->constrained()->cascadeOnDelete();
            $table->index(['tenant_id', 'webhook_id']);
        });

        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->json('calculation_log')->nullable()->after('net_cents');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->dropColumn('calculation_log');
        });

        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'webhook_id']);
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
