<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's switch for sending invitations by text message, and the share
 * of the daily cap they may use. Off until SMS is live: while it is off, a
 * person with only a phone is activated in person. The share keeps a large
 * organisation's invitations from using up the sign-in codes every other
 * organisation needs that day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_settings', function (Blueprint $table) {
            $table->boolean('invitations_enabled')->default(false)->after('daily_cap');
            $table->unsignedTinyInteger('invitation_share')->default(30)->after('invitations_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('sms_settings', function (Blueprint $table) {
            $table->dropColumn(['invitations_enabled', 'invitation_share']);
        });
    }
};
