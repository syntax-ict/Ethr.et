<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An optional Google Maps JavaScript API key, entered once in the platform
 * console. With one, the map pickers offer Google Maps beside OpenStreetMap;
 * without one nothing is sent to Google. It is a browser key, restricted by
 * referrer on Google's side, so it is not stored encrypted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->string('google_maps_key', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn('google_maps_key');
        });
    }
};
