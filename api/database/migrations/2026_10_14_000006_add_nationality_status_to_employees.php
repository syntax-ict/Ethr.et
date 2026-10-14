<?php

declare(strict_types=1);

use App\Support\NationalityBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `nationality_status` decides pension coverage instead of the free-text
     * `nationality` (App\Enums\NationalityStatus). Existing rows are mapped by
     * NationalityBackfill: known Ethiopian spellings and blank → ethiopian,
     * anything else → foreign and flagged for review. The flag is cleared when
     * someone saves the employee's status.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('nationality_status', 20)->default('ethiopian');
            $table->boolean('nationality_needs_review')->default(false);
        });

        $result = NationalityBackfill::run();

        if ($result['flagged'] > 0) {
            fwrite(STDERR, sprintf(
                "nationality_status: %d employee(s) mapped to foreign and flagged for review (ids: %s).\n",
                $result['flagged'],
                implode(', ', array_slice($result['flagged_ids'], 0, 50)).($result['flagged'] > 50 ? ', …' : ''),
            ));
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['nationality_status', 'nationality_needs_review']);
        });
    }
};
