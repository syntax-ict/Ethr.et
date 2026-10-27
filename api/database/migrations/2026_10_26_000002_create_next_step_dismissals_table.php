<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a person dismissed or snoozed on their Next Steps list.
 *
 * Until now this lived in the browser's localStorage, so a step dismissed on
 * the laptop came back on the phone. One row per person and step; the signature
 * is what the step looked like when it was dismissed, so it returns when the
 * state behind it changes (three requests became five) and stays away while
 * nothing moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('next_step_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('step_id', 64);
            $table->string('signature', 64);
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'step_id'], 'nsd_user_step_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('next_step_dismissals');
    }
};
