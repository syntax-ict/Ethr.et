<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who a ZKTeco ADMS terminal last said is enrolled on it. ETHR cannot call
     * an ADMS device, so the list is asked for with a command and arrives later
     * as upload lines; this table holds what arrived, one row per person.
     *
     * Only what a roster needs. The device also sends each person's password
     * and fingerprint and face templates; those are biometric or secret and
     * are never written, only counted (fingerprint_count, face_registered).
     */
    public function up(): void
    {
        Schema::create('device_enrollment_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('device_user_id', 64);
            $table->string('name', 100)->nullable();
            $table->string('card_number', 40)->nullable();
            $table->boolean('is_admin')->default(false);
            $table->unsignedTinyInteger('fingerprint_count')->nullable();
            $table->boolean('face_registered')->nullable();
            $table->timestamp('seen_at')->useCurrent();
            $table->timestamps();

            // Named: the generated names pass MariaDB's 64-character limit.
            $table->unique(['device_id', 'device_user_id'], 'dev_enrol_snap_device_user_unique');
            $table->index(['tenant_id', 'device_id', 'seen_at'], 'dev_enrol_snap_tenant_device_seen_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_enrollment_snapshots');
    }
};
