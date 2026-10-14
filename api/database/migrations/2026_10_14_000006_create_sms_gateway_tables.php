<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SMS delivery configured from the platform console instead of `.env`.
 *
 * Three global tables — no `tenant_id`, because SMS is bought and switched on
 * once for the whole platform, by the super admin:
 *
 * - `sms_gateways`: at most two HTTP gateways, keyed by `slot` (`primary` for
 *   every number, `secondary` for the numbers a route sends elsewhere). The
 *   credential is an encrypted column and is never returned by any API.
 * - `sms_settings`: one row — the on/off switch, the route, the daily cap.
 * - `sms_sends`: one row per message a gateway accepted, counted for the daily
 *   cap. It records which gateway and why, never the number or the text.
 *
 * A separate model rather than more columns on `platform_settings`: that row
 * feeds the public site content and its resource returns most of its columns,
 * so a secret beside them is one careless `toArray()` from the marketing page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('slot', 16)->unique();
            $table->string('label', 120)->nullable();
            $table->string('endpoint', 500);
            $table->string('method', 8)->default('form');
            $table->string('auth_type', 12)->default('none');
            $table->string('auth_header', 100)->nullable();
            $table->string('auth_username', 191)->nullable();
            $table->text('auth_secret')->nullable();
            $table->string('username_field', 64)->nullable();
            $table->string('password_field', 64)->nullable();
            $table->string('recipient_field', 64)->default('to');
            $table->string('message_field', 64)->default('message');
            $table->string('sender_field', 64)->nullable();
            $table->string('sender_id', 64)->nullable();
            $table->string('number_format', 16)->default('international');
            $table->string('success_json_path', 191)->nullable();
            $table->string('success_json_value', 191)->nullable();
            $table->string('success_body_contains', 191)->nullable();
            $table->unsignedSmallInteger('timeout_seconds')->default(10);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_test_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->unsignedSmallInteger('last_test_status')->nullable();
            $table->text('last_test_response')->nullable();
            $table->timestamps();
        });

        Schema::create('sms_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->char('route_prefix', 1)->nullable();
            $table->unsignedInteger('daily_cap')->default(500);
            $table->timestamps();
        });

        Schema::create('sms_sends', function (Blueprint $table) {
            $table->id();
            $table->string('slot', 16);
            $table->string('purpose', 24);
            $table->timestamp('created_at')->useCurrent();
            $table->index('created_at', 'sms_sends_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_sends');
        Schema::dropIfExists('sms_settings');
        Schema::dropIfExists('sms_gateways');
    }
};
