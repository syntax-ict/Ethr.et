<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Services\MfaService;
use Illuminate\Console\Command;

/**
 * Turns MFA off for an account whose owner lost their authenticator, when no
 * one in the app can: a platform super admin, who has no one above them, or
 * the only tenant admin of an organisation. Everyone else is reset by their
 * tenant admin from Settings → Users. On the Plesk host, run it from the
 * Laravel Toolkit's Artisan tab:
 *
 *   ethr:reset-mfa admin@ethr.et                      (platform account)
 *   ethr:reset-mfa owner@acme.et --tenant=acme        (a tenant's user)
 */
class ResetMfaCommand extends Command
{
    protected $signature = 'ethr:reset-mfa
        {email : The account whose two-factor authentication to turn off}
        {--tenant= : The organisation\'s slug; leave out for a platform super admin}';

    protected $description = 'Turn off two-factor authentication for someone who lost their authenticator';

    public function handle(MfaService $mfa, CurrentTenant $currentTenant): int
    {
        $slug = $this->option('tenant');
        $tenant = null;

        if ($slug !== null) {
            $tenant = Tenant::where('subdomain', $slug)->first();

            if ($tenant === null) {
                $this->error("No organisation has the slug \"{$slug}\".");

                return self::FAILURE;
            }

            $currentTenant->set($tenant);
        }

        // Platform accounts have no tenant, so the tenant scope cannot find
        // them. The predicate is stated here instead: the named tenant's
        // users, or (with no --tenant) platform accounts only.
        $user = User::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant?->id)
            ->where('email', $this->argument('email'))
            ->first();

        if ($user === null) {
            $this->error($tenant === null
                ? 'No platform account has that email. For a user in an organisation, add --tenant=<slug>.'
                : "No user in {$tenant->subdomain} has that email.");

            return self::FAILURE;
        }

        if (! $user->mfa_enabled) {
            $this->warn("{$user->email} does not have two-factor authentication turned on. Nothing changed.");

            return self::SUCCESS;
        }

        $emailed = $mfa->reset($user, by: 'console');

        $this->info("Two-factor authentication is off for {$user->email}. They can sign in with their password and set it up again.");

        if (! $emailed) {
            $this->warn('The email telling them could not be sent; tell them yourself. The mail error is in the log.');
        }

        return self::SUCCESS;
    }
}
