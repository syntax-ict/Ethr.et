<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cover photo for a story on the organisation's news pages (2026-10-16):
 * where FileStorageService stored the re-encoded JPEG, its content version,
 * its size and its narrower copies, as `tenants.brand_assets` keeps the
 * organisation's own cover. Nullable, so every existing story simply has none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            $table->json('cover')->nullable()->after('featured');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            $table->dropColumn('cover');
        });
    }
};
