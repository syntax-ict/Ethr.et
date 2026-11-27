<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether the console's backup storage is in Ethiopia, as stated by the
     * super admin who saved it, and who said so when.
     *
     * Proclamation 1321/2024 Art. 22(1): in production a copy goes there only
     * while this is true (App\Support\BackupResidency). False for a row saved
     * before this migration, so an existing storage stops taking copies in
     * production until someone states where it is. That is the point: nobody
     * has said so yet.
     */
    public function up(): void
    {
        Schema::table('backup_storage_settings', function (Blueprint $table): void {
            $table->boolean('located_in_ethiopia')->default(false);
            $table->timestamp('located_confirmed_at')->nullable();
            $table->foreignId('located_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('backup_storage_settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('located_confirmed_by');
            $table->dropColumn(['located_in_ethiopia', 'located_confirmed_at']);
        });
    }
};
