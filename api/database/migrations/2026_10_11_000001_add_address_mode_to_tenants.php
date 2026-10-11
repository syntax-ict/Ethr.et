<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which address an organisation chose: `path`, `subdomain` or
     * `custom_domain` (App\Enums\TenantAddressMode). NULL means it has not
     * chosen, and gets what FrontendUrl always gave it: a verified custom
     * domain, else the subdomain where those are served, else the path. So an
     * existing deployment changes nothing by migrating.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('address_mode', 16)->nullable()->after('custom_domain_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('address_mode');
        });
    }
};
