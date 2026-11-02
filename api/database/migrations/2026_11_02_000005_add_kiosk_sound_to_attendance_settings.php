<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a shared kiosk says when someone punches: `off`, `tones` (a short
     * pattern per result) or `voice` (a few words in the kiosk's own
     * language, falling back to tones where the tablet has no voice for it).
     * Tones by default, because a person who punches and walks away does not
     * see a refusal on the screen.
     */
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table): void {
            $table->string('kiosk_sound', 8)->default('tones');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table): void {
            $table->dropColumn('kiosk_sound');
        });
    }
};
