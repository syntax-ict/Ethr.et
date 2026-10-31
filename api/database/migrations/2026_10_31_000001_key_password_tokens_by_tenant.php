<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Reset and invitation tokens are now stored under "{tenant_id}|{email}"
     * (User::getEmailForPasswordReset, written out here so this migration
     * does not move if the model does). A row stored under the bare address is
     * moved to its account's key when exactly one account has that address,
     * so an invitation sent before the deploy still works. A row whose address
     * belongs to several organisations cannot be told apart and is removed;
     * the person asks for a new link. Tokens last 60 minutes and 72 hours, so
     * nothing of value is lost.
     */
    public function up(): void
    {
        foreach (['password_reset_tokens', 'account_activation_tokens'] as $table) {
            $rows = DB::table($table)->where('email', 'not like', '%|%')->get(['email']);

            foreach ($rows as $row) {
                $tenants = DB::table('users')->where('email', $row->email)->distinct()->pluck('tenant_id');

                if ($tenants->count() === 1) {
                    $key = ($tenants->first() ?? 0).'|'.$row->email;
                    DB::table($table)->where('email', $key)->delete();
                    DB::table($table)->where('email', $row->email)->update(['email' => $key]);
                } else {
                    DB::table($table)->where('email', $row->email)->delete();
                }
            }
        }
    }

    public function down(): void
    {
        // Keys that cannot be moved back unambiguously are left; tokens expire
        // within 72 hours.
        foreach (['password_reset_tokens', 'account_activation_tokens'] as $table) {
            DB::table($table)->where('email', 'like', '%|%')->delete();
        }
    }
};
