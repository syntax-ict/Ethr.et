<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work sites: places people may punch besides their branch.
 *
 * A branch had one circle, so a project site, a client's office, a farm or a
 * branch's second building could not be a place to punch at all. A site is a
 * named circle. Everyone at its branch may use it (an annex, a warehouse),
 * and it can be assigned to further employees or whole departments (field
 * teams who move between sites).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_sites', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('name_am')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('radius_meters')->default(150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('attendance_site_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_site_id')->constrained()->cascadeOnDelete();
            $table->string('assignable_type');
            $table->unsignedBigInteger('assignable_id');
            $table->timestamps();

            $table->unique(['attendance_site_id', 'assignable_type', 'assignable_id'], 'site_assign_unique');
            $table->index(['tenant_id', 'assignable_type', 'assignable_id'], 'site_assign_owner');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_site_assignments');
        Schema::dropIfExists('attendance_sites');
    }
};
