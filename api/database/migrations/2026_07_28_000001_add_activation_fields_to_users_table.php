<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Who provisioned this account (null for self-registered tenant admins,
            // SSO/SCIM, or the platform super admin).
            $table->unsignedBigInteger('invited_by')->nullable()->after('status');
            // Lifecycle stamps for the invite -> activate flow.
            $table->timestamp('invited_at')->nullable()->after('invited_by');
            $table->timestamp('activated_at')->nullable()->after('invited_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['invited_by', 'invited_at', 'activated_at']);
        });
    }
};
