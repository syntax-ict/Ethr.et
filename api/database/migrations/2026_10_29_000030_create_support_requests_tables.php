<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Get help: someone in an organisation asks for help from inside ETHR.
     *
     * - A request is answered first by the organisation's own support people
     *   (support.manage). They pass it to ETHR when it needs the platform;
     *   `handled_by` says who holds it now and `escalated_at` that ETHR does.
     * - `context` is what the screen knew when the person asked: the page, the
     *   last failed requests, offline punches waiting. It is what saves the
     *   first round of "which organisation, which page, what did it say".
     * - Messages carry who wrote them: the requester, the organisation or ETHR.
     *   An ETHR author is a platform account with no tenant, so the user
     *   reference is plain.
     */
    public function up(): void
    {
        Schema::create('support_requests', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requester_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 30);
            $table->text('body');
            $table->string('status', 15)->default('open');
            $table->string('handled_by', 15)->default('organisation');
            $table->timestamp('escalated_at')->nullable();
            $table->foreignId('escalated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('escalation_note')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'support_requests_tenant_status_index');
            $table->index(['escalated_at', 'status'], 'support_requests_escalated_status_index');
        });

        Schema::create('support_request_messages', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('support_request_id')->constrained()->cascadeOnDelete();
            $table->string('author', 15);
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['support_request_id', 'id'], 'support_request_messages_request_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_request_messages');
        Schema::dropIfExists('support_requests');
    }
};
