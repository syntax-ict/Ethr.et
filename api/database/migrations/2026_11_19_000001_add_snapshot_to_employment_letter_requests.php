<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an issued employment letter said on the day it was issued. `snapshot`
 * is text because the model stores it encrypted (it can hold a salary); no
 * backfill, a letter issued before this freezes at its first download.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employment_letter_requests', function (Blueprint $table) {
            $table->text('snapshot')->nullable()->after('rejection_reason');
            $table->timestamp('snapshot_at')->nullable()->after('snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('employment_letter_requests', function (Blueprint $table) {
            $table->dropColumn(['snapshot', 'snapshot_at']);
        });
    }
};
