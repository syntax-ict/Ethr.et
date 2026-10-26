<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time codes that HR issues in person to an employee who has no email and
 * no phone, so an activation link or a sign-in code has nowhere to go.
 *
 * Only a keyed hash of the code is kept (`code_hash`, HMAC-SHA256 under the
 * application key); the code itself exists once, in the response that issued
 * it. It is spent by one conditional update, so two requests cannot both
 * redeem it. `document_type` records which identity document HR checked and
 * never its number. `failed_attempts` locks the code after a handful of wrong
 * guesses at the typed-entry path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_activation_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 12);
            $table->char('code_hash', 64);
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('document_type', 20);
            $table->string('note', 255)->nullable();
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('expires_at')->useCurrent();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code_hash'], 'acc_act_code_hash_uq');
            $table->index(['tenant_id', 'user_id', 'consumed_at', 'revoked_at'], 'acc_act_user_live_idx');
            $table->index('expires_at', 'acc_act_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_activation_codes');
    }
};
