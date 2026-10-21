<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An organisation's own export of its data (blueprint assessment A12, P12).
 * Until now only a platform operator could produce one, as a JSON file that
 * left out every uploaded document and could not be downloaded by the
 * customer. The archive is deleted when it expires, since the host's storage
 * quota is shared with the database and every uploaded file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_exports', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('queued');
            $table->string('path', 255)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('file_count')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'tenant_exports_tenant_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_exports');
    }
};
