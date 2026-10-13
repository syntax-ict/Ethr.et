<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invitation links get their own token table so they can last 72 hours
     * while password-reset links keep 60 minutes (config/auth.php,
     * `passwords.activations`; production QA 2026-10-11, P11). Same shape as
     * `password_reset_tokens`, which Laravel's token repository expects.
     */
    public function up(): void
    {
        Schema::create('account_activation_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_activation_tokens');
    }
};
