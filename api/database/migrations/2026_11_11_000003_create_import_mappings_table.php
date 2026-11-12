<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How an organisation's other system names its columns (One employee
 * identity, PR 8).
 *
 * Another HR or payroll system exports a list with its own column names.
 * Somebody says once which of them is the name, the employee number and so
 * on, and that is kept here per source, so next month's export is read
 * without being asked again.
 *
 * `slug` is the source's own name for itself and never changes: an "ID in
 * the other system" is kept against it (employee_external_identities
 * .source_ref, 100 characters), so renaming "Peachtree" to "Old payroll"
 * does not orphan the ids already linked.
 *
 * `columns` is a list of {header, column}: the column name as the file
 * writes it, and the ETHR column it is, or null for one that is left out.
 * It holds column names only, never a person's details.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_mappings', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('slug', 60);
            $table->json('columns');
            $table->string('calendar', 12)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_mappings');
    }
};
