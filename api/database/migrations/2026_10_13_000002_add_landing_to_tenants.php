<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The organisation's own words on its sign-in page (ethr.et/{slug}): a
     * headline, a welcome message, who to contact for help, and the panel's
     * background. Null means ETHR's defaults throughout.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->json('landing')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('landing');
        });
    }
};
