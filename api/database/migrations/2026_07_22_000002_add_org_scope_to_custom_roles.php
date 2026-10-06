<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_roles', function (Blueprint $table) {
            $table->string('org_scope', 20)->default('self')->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('custom_roles', function (Blueprint $table) {
            $table->dropColumn('org_scope');
        });
    }
};
