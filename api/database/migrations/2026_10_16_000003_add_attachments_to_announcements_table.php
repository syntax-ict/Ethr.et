<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documents attached to a story (2026-10-16): up to three PDFs, each kept as
 * its id, the name it was uploaded under, its size and where
 * FileStorageService stored it. Nullable, so every existing story simply has
 * none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            $table->json('attachments')->nullable()->after('cover');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            $table->dropColumn('attachments');
        });
    }
};
