<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "Powered by ETHR" mark at the foot of every organisation's sidebar,
 * owned by the platform operator rather than by the organisations it sits in.
 *
 * On by default, so the row this adds to changes nothing until an operator
 * turns the mark off in the console. The label and the link are null until
 * set, and null means the built-in wording ("Powered by ETHR", translated) and
 * the public site, so there is no copy of either in the database to drift
 * from the product.
 *
 * Columns on the singleton row, like every other platform fact: the same
 * `admin.manage` gate, the same audit entry and the same tenant-isolation
 * decision (global by design) cover them without anything new.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->boolean('product_mark_enabled')->default(true);
            $table->string('product_mark_label', 60)->nullable();
            $table->string('product_mark_label_am', 60)->nullable();
            $table->string('product_mark_url', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn([
                'product_mark_enabled',
                'product_mark_label',
                'product_mark_label_am',
                'product_mark_url',
            ]);
        });
    }
};
