<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Open positions an organisation advertises. `visibility` decides the
     * audience: `internal` is for signed-in employees only, `public` is also
     * listed on the organisation's sign-in page (ethr.et/{slug}). A vacancy is
     * advertised only while `status` is `open`, it has been published and its
     * closing date has not passed.
     *
     * Announcements gain `show_on_landing`, the same choice for news: off by
     * default, because everything an announcement says was written for
     * employees.
     */
    public function up(): void
    {
        Schema::create('vacancies', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 150);
            $table->string('employment_type', 20);
            $table->string('location', 120)->nullable();
            $table->text('description');
            $table->text('requirements')->nullable();
            $table->unsignedSmallInteger('positions')->default(1);
            $table->string('visibility', 10)->default('internal');
            $table->string('status', 10)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->date('closes_on')->nullable();
            $table->string('apply_email', 120)->nullable();
            $table->string('apply_url', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status', 'visibility']);
        });

        Schema::table('announcements', function (Blueprint $table): void {
            $table->boolean('show_on_landing')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            $table->dropColumn('show_on_landing');
        });

        Schema::dropIfExists('vacancies');
    }
};
