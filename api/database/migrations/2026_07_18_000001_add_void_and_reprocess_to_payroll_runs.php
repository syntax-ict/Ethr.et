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
            $table->timestamp('voided_at')->nullable()->after('approved_at');
            $table->unsignedBigInteger('voided_by')->nullable()->after('voided_at');
            $table->string('void_reason', 500)->nullable()->after('voided_by');
            $table->unsignedBigInteger('reprocessed_from_id')->nullable()->after('void_reason');

            $table->foreign('voided_by')->references('id')->on('users');
            $table->foreign('reprocessed_from_id')->references('id')->on('payroll_runs');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropForeign(['voided_by']);
            $table->dropForeign(['reprocessed_from_id']);
            $table->dropColumn(['voided_at', 'voided_by', 'void_reason', 'reprocessed_from_id']);
        });
    }
};
