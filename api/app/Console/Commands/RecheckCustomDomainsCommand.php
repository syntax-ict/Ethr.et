<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\CustomDomainVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Re-checks every verified custom domain, and reports the ones that no longer
 * check out. It never revokes.
 *
 * Verification is a moment: an organisation can later remove its TXT record
 * or point the name elsewhere, and every link ETHR e-mails would then lead
 * somewhere that is not this platform. Revoking on a failed lookup would be
 * worse, because one DNS hiccup would take an organisation's address away. So
 * each failure is an audit row (`platform.tenant.domain_check_failed`, in the
 * platform audit view) and a warning in the log, for a person to look at.
 */
final class RecheckCustomDomainsCommand extends Command
{
    protected $signature = 'tenancy:recheck-custom-domains';

    protected $description = 'Re-check verified custom domains and report any whose DNS no longer checks out';

    public function handle(CustomDomainVerifier $verifier): int
    {
        $failed = 0;

        Tenant::query()
            ->whereNotNull('custom_domain')
            ->whereNotNull('custom_domain_verified_at')
            ->orderBy('id')
            ->each(function (Tenant $tenant) use ($verifier, &$failed): void {
                $checks = $verifier->check($tenant);
                if ($checks['txt'] && $checks['cname']) {
                    return;
                }

                $failed++;
                AuditLog::record('platform.tenant.domain_check_failed', $tenant, [
                    'domain' => $tenant->custom_domain,
                    'checks' => $checks,
                ]);
                Log::warning("Custom domain {$tenant->custom_domain} no longer checks out", [
                    'tenant' => $tenant->public_id,
                    'checks' => $checks,
                ]);
            });

        $this->info($failed === 0 ? 'Every verified custom domain checks out.' : "{$failed} verified custom domain(s) no longer check out; see the platform audit log.");

        return self::SUCCESS;
    }
}
