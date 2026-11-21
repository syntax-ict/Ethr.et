<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a gateway did with real messages, not only with the console's test: how
 * many it refused in a row, when it last delivered, what it last said, and
 * until when it is left alone after refusing too many. A gateway that refuses
 * everything (credit gone, account in beta, sender name revoked) stops being
 * offered at sign-in instead of silently losing every code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_gateways', function (Blueprint $table) {
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->unsignedSmallInteger('last_failure_status')->nullable();
            $table->text('last_failure_response')->nullable();
            $table->timestamp('paused_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sms_gateways', function (Blueprint $table) {
            $table->dropColumn([
                'consecutive_failures', 'last_success_at', 'last_failure_at',
                'last_failure_status', 'last_failure_response', 'paused_until',
            ]);
        });
    }
};
