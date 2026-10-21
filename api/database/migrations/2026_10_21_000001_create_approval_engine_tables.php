<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The approval engine, Phase A (docs/APPROVAL-ENGINE-DESIGN.md). A workflow is
 * an ordered list of steps per request type; an instance is one request's
 * journey through a snapshot of that workflow; an action is one decision on
 * it, append-only. With no workflow configured, a request runs the built-in
 * single step, which is how every request was decided before this existed.
 *
 * The current step's approver is resolved when the step is entered and kept
 * on the instance (a user, a role, or neither for "anyone with the
 * permission"), so the approvals inbox can filter in SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_workflows', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('request_type', 40);
            $table->string('name', 120);
            $table->unsignedSmallInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('conditions')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'request_type', 'is_active'], 'approval_workflows_lookup');
        });

        Schema::create('approval_workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approval_workflow_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('approver_kind', 20);
            $table->string('approver_ref', 64)->nullable();
            $table->unsignedSmallInteger('sla_hours')->default(48);
            $table->boolean('can_return')->default(false);
            $table->timestamps();

            $table->unique(['approval_workflow_id', 'position'], 'approval_steps_workflow_position');
        });

        Schema::create('approval_instances', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('approvable_type', 120);
            $table->unsignedBigInteger('approvable_id');
            $table->foreignId('approval_workflow_id')->nullable()->constrained()->nullOnDelete();
            $table->json('workflow_snapshot');
            $table->unsignedTinyInteger('current_position')->default(1);
            $table->foreignId('current_approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('current_approver_role', 20)->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['approvable_type', 'approvable_id'], 'approval_instances_approvable');
            $table->index(['tenant_id', 'status'], 'approval_instances_tenant_status');
        });

        Schema::create('approval_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approval_instance_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('on_behalf_of_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 20);
            $table->string('comment', 1000)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('approval_instance_id', 'approval_actions_instance');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_actions');
        Schema::dropIfExists('approval_instances');
        Schema::dropIfExists('approval_workflow_steps');
        Schema::dropIfExists('approval_workflows');
    }
};
