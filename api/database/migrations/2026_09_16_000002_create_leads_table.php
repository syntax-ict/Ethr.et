<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enquiries from the public contact form.
 *
 * Until now `ContactController` was one `Log::info` and a 201. The sender was
 * told "we will get back to you within 24 hours" and nothing kept the message:
 * every enquiry from the public site lived only in `storage/logs/laravel.log`,
 * a file with no retention policy, no access boundary and no deletion path —
 * which is also where the sender's name, email and phone ended up in plaintext,
 * forty lines from copy selling data-protection compliance.
 *
 * Global by design, and for a different reason from the other entries in
 * TenantIsolationTest::GLOBAL_MODELS. Those are catalogues every tenant reads.
 * This is the opposite: a lead is a *prospect*, so no tenant exists to own the
 * row, and no tenant should ever see it. The absence of `tenant_id` is
 * therefore not a grant of shared access — it is the absence of an owner, and
 * the only protection is that nothing tenant-facing may ever query this table.
 * Today nothing reads it at all; the notification is the delivery path.
 *
 * DELIBERATELY NOT STORED: IP address and user agent. They would help with spam
 * forensics, but the throttle already keys on IP without retaining it, and the
 * least defensible thing to do with a stranger's enquiry is to keep more of
 * them than the enquiry needs. The honeypot in `ContactRequest` covers bots
 * without recording anyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('organization')->nullable();
            $table->text('message');

            $table->timestamps();

            // The only access pattern there will ever be for a leads list:
            // newest first. Added now because adding it later means an
            // ALTER on a table that is only ever appended to.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
