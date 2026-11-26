<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The inbox a pushing terminal's punches land in before they are processed.
 *
 * A terminal re-sends any event it is not answered 200 for, at once, and the
 * host has five PHP workers. So the request does one insert and answers 200;
 * ProcessDeviceInboundPunchesJob then works the device's rows in arrival
 * order (`id`), which is the order the attendance engine's `auto` direction
 * depends on.
 *
 * `idempotency_key` is the key the punch is recorded under, so a re-sent
 * event is one row here and one record in the engine. Index names are given
 * explicitly: MariaDB refuses an identifier over 64 characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_inbound_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('badge', 64);
            $table->string('punch_type', 20);
            $table->string('occurred_raw', 64);
            $table->string('idempotency_key', 191);
            $table->timestamp('processed_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error', 255)->nullable();
            $table->timestamps();

            $table->unique('idempotency_key', 'dip_idempotency_unique');
            $table->index(['device_id', 'processed_at', 'id'], 'dip_device_pending_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_inbound_punches');
    }
};
