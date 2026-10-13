<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Employee voice: how people say how work is going, raise what worries
     * them, and thank each other.
     *
     * - pulse_responses holds the weekly rating with NO link to the person.
     *   That someone already answered this week lives only in the cache, keyed
     *   by user and week: a participation table would let anyone with the
     *   database line up the n-th participant with the n-th answer.
     * - concerns are raised named or anonymously. An anonymous one stores no
     *   reporter at all; its author follows it with a one-time code, kept
     *   only as a SHA-256 hash.
     * - recognitions are colleagues thanking colleagues.
     */
    public function up(): void
    {
        Schema::create('pulse_responses', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->char('week', 8);
            $table->unsignedTinyInteger('score');
            $table->json('tags')->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            // The day only: a timestamp to the second could be matched with
            // who was signed in at that moment.
            $table->date('submitted_on');

            $table->index(['tenant_id', 'week'], 'pulse_responses_tenant_week_index');
        });

        Schema::create('concerns', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reporter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('anonymous')->default(false);
            $table->char('tracking_hash', 64)->nullable()->unique();
            $table->string('category', 30);
            $table->string('subject', 150);
            $table->text('description');
            $table->string('status', 15)->default('new');
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'concerns_tenant_status_index');
        });

        Schema::create('concern_messages', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('concern_id')->constrained()->cascadeOnDelete();
            $table->string('author', 10);
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('recognitions', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('to_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('value', 30);
            $table->string('message', 500);
            $table->timestamps();

            $table->index(['tenant_id', 'created_at'], 'recognitions_tenant_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recognitions');
        Schema::dropIfExists('concern_messages');
        Schema::dropIfExists('concerns');
        Schema::dropIfExists('pulse_responses');
    }
};
