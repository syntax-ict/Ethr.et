<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The addresses an organisation used to have.
 *
 * From 2026-10-26 a tenant admin can rename the organisation's address
 * (ethr.et/{slug}, {slug}.ethr.et). The old name is not released: links
 * already sent, a bookmarked sign-in page, a browser that still sends the old
 * name as X-Tenant and an identity provider posting to the old SSO address all
 * keep reaching the same organisation, and no one else can register the name
 * and receive them. The organisation can take a retired name back.
 *
 * `subdomain` is unique across every organisation, as `tenants.subdomain` is.
 * A row goes only when its organisation is hard deleted, which the console
 * never does (docs/operations/TENANT-DELETION.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_subdomain_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('subdomain', 63)->unique();
            $table->timestamps();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_subdomain_aliases');
    }
};
