<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who owns an organisation: the person ETHR's support calls and who alone
     * may hand ownership to another administrator. There was no such field;
     * "the first active administrator" stood in for it. Every organisation is
     * given that person as its owner here, so nothing changes for anyone on
     * the day this runs. Transfers are recorded in the audit log.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $owner = DB::table('users')
                ->where('tenant_id', $tenantId)
                ->where('role', 'tenant_admin')
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->value('id');

            if ($owner !== null) {
                DB::table('tenants')->where('id', $tenantId)->update(['owner_user_id' => $owner]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('owner_user_id');
        });
    }
};
