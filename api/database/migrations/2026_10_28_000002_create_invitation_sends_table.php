<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per invitation ETHR tried to send: by email, or by text message once
 * SMS invitations are switched on. A bulk send groups its rows under one
 * `batch` (a ULID), which is what the progress screen polls; a single resend
 * has none.
 *
 * `error_code` is a short code ETHR chose, never an exception message: a mail
 * or gateway error can carry a host name, a credential or the address itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitation_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('batch', 26)->nullable();
            $table->string('channel', 12);
            $table->string('status', 12);
            $table->boolean('reminder')->default(false);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error_code', 40)->nullable();
            $table->foreignId('queued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('queued_at')->useCurrent();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'batch'], 'inv_sends_batch_idx');
            $table->index(['tenant_id', 'user_id', 'queued_at'], 'inv_sends_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_sends');
    }
};
