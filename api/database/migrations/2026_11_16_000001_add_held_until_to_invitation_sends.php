<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An invitation held for a later day because its organisation, or all
 * organisations together, used the day's share of the mail cap. The row stays
 * `queued`; `held_until` says when it is due. The index serves the count of
 * what an organisation sent today (EmailInvitationCeiling::sentToday).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitation_sends', function (Blueprint $table) {
            $table->timestamp('held_until')->nullable()->after('sent_at');
            $table->index(['tenant_id', 'sent_at'], 'inv_sends_sent_idx');
        });
    }

    public function down(): void
    {
        Schema::table('invitation_sends', function (Blueprint $table) {
            $table->dropIndex('inv_sends_sent_idx');
            $table->dropColumn('held_until');
        });
    }
};
