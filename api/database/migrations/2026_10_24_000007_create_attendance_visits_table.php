<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field visits: a stop a field worker logs during the day (a client, a farm,
 * a health post) with where, when, an optional note and photo.
 *
 * A visit is evidence of work, not a punch: it never opens or closes an
 * attendance record and never changes worked hours. It is kept like
 * attendance (never deleted); its photo follows the selfie retention period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_visits', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_site_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy', 8, 1)->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->string('note', 500)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('idempotency_key', 64);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'employee_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_visits');
    }
};
