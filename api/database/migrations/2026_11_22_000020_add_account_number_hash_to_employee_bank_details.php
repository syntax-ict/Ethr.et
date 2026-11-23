<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A keyed hash of each account number, so two people paid into one
     * account can be found before a run is approved (PreApprovalChecks). The
     * number itself is encrypted and cannot be compared in SQL.
     *
     * The column starts empty. Existing rows are filled by
     * BackfillBankAccountHashesJob, a slice at a time, not here: the release's
     * migrate step runs inside one web request with a 30-second limit.
     */
    public function up(): void
    {
        Schema::table('employee_bank_details', function (Blueprint $table): void {
            $table->char('account_number_hash', 64)->nullable()->after('account_number');
            $table->index(['tenant_id', 'account_number_hash'], 'employee_bank_details_account_hash_index');
        });
    }

    public function down(): void
    {
        Schema::table('employee_bank_details', function (Blueprint $table): void {
            $table->dropIndex('employee_bank_details_account_hash_index');
            $table->dropColumn('account_number_hash');
        });
    }
};
