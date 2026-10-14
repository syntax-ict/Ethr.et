<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The organisation's other brand images, beside the logo that
 * `logo_storage_path` already holds: a logo for dark backgrounds, a square
 * mark (favicon, app icon, link-preview fallback) and a cover photo with its
 * focal point and the widths it was re-encoded at. One JSON column keyed by
 * kind, because every entry has the same shape and none is queried on its own:
 * {"mark": {"path": "...", "version": "...", "width": 512, "height": 512}, ...}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->json('brand_assets')->nullable()->after('logo_version');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('brand_assets');
        });
    }
};
