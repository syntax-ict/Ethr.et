<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An organisation's own logo, uploaded rather than linked. Until now the
     * logo could only be an https URL, and most organisations have nowhere to
     * host one, so they could not brand their sign-in page at all.
     *
     * `logo_storage_path` is the file on the configured disk; `logo_version` is
     * a content hash that appears in the public URL, so a new upload is a new
     * URL and the old one can be cached forever. Both null = no upload; the
     * linked `logo_path`, if any, is used instead.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('logo_storage_path', 255)->nullable()->after('logo_path');
            $table->string('logo_version', 16)->nullable()->after('logo_storage_path');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['logo_storage_path', 'logo_version']);
        });
    }
};
