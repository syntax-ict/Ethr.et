<?php

declare(strict_types=1);

use App\Support\Reference\ReferenceCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The places and banks the forms offer as pick lists: regional states and
     * city administrations, the sub-cities of Addis Ababa, the main towns, and
     * the banks. One global table, like `organization_types`, no tenant owns a
     * row.
     *
     * The rows themselves live in `database/data/reference/` and are loaded
     * here, not left to a seed call. Deploy and `migrate` are one step, while
     * seeding is a separate call the owner makes; a form that checks a code
     * against these rows must never meet an empty table because that call was
     * late. `ReferenceDataSeeder` re-syncs the same files after one changes.
     */
    public function up(): void
    {
        Schema::create('reference_items', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('kind', 30);
            $table->string('code', 60);
            $table->string('parent_code', 60)->nullable();
            $table->string('name', 150);
            $table->string('name_am', 150);
            $table->string('name_om', 150)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'code']);
            $table->index(['kind', 'parent_code']);
            $table->index(['kind', 'is_active', 'sort_order']);
        });

        ReferenceCatalogue::sync();
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_items');
    }
};
