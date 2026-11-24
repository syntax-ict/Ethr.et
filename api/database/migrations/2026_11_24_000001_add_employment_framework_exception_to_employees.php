<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An employee can sit outside the organisation's employment framework (a
     * manager of a private company, mixed staff of a public enterprise).
     * Default false: everyone is covered unless a person says otherwise.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('employees', 'outside_employment_framework')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->boolean('outside_employment_framework')->default(false);
            });
        }

        if (! Schema::hasColumn('employees', 'employment_framework_note')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->string('employment_framework_note', 255)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['employment_framework_note', 'outside_employment_framework'] as $column) {
            if (Schema::hasColumn('employees', $column)) {
                Schema::table('employees', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
