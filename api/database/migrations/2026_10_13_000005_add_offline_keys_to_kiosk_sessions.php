<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A key pair per kiosk for punches made while the kiosk is offline (a
     * power cut, a dropped connection). The terminal holds only the public
     * key and seals each punch - employee code and PIN included - so what
     * waits in the browser cannot be read or altered there. The private key
     * is stored encrypted with the application key and never leaves the
     * server. Created on first use.
     */
    public function up(): void
    {
        Schema::table('kiosk_sessions', function (Blueprint $table): void {
            $table->text('offline_public_key')->nullable();
            $table->text('offline_private_key')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('kiosk_sessions', function (Blueprint $table): void {
            $table->dropColumn(['offline_public_key', 'offline_private_key']);
        });
    }
};
