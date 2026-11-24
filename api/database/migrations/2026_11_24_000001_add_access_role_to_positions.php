<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The access a job title gives. NULL means ordinary employee access.
     * Only the four roles below the organisation admin can be mapped; the
     * allowed list is enforced by the form request, not the column.
     */
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->string('access_role', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->dropColumn('access_role');
        });
    }
};
