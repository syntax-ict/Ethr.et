<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Announcements as the organisation's public news (2026-10-16): a category a
 * reader can filter by, a featured flag that puts a story first, and the time
 * its audience was told, so publishing a draft, scheduling one or publishing
 * it again notifies people once.
 *
 * Backward compatible: all three are nullable or default false, and existing
 * published announcements are marked as already notified, since they were
 * when they were created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            $table->string('category', 20)->nullable()->after('body');
            $table->boolean('featured')->default(false)->after('show_on_landing');
            $table->timestamp('notified_at')->nullable()->after('published_at');
        });

        DB::table('announcements')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->update(['notified_at' => DB::raw('published_at')]);
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            $table->dropColumn(['category', 'featured', 'notified_at']);
        });
    }
};
