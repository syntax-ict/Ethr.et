<?php

declare(strict_types=1);

use App\Contracts\DnsResolver;
use App\Models\AuditLog;
use App\Models\Tenant;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * A verified custom domain is re-checked weekly, and a failure is REPORTED,
 * never acted on.
 *
 * Verification is a moment. An organisation can later delete the TXT record or
 * point the name somewhere else, and every link ETHR e-mails would then lead
 * to a host that is not this platform, with nobody told. Revoking on a failed
 * lookup would be worse: one DNS hiccup would take an organisation's address
 * away. So the sweep writes an audit row and a warning per failing domain, and
 * changes nothing (owner delegated the call, 2026-10-08).
 */
function recheckDns(array $txt = [], array $cname = []): void
{
    app()->instance(DnsResolver::class, new class($txt, $cname) implements DnsResolver
    {
        public function __construct(private array $txtRecords, private array $cnameRecords) {}

        public function txt(string $name): array
        {
            return $this->txtRecords[$name] ?? [];
        }

        public function cname(string $name): ?string
        {
            return $this->cnameRecords[$name] ?? null;
        }
    });
}

function verifiedDomainTenant(string $domain, string $token): Tenant
{
    $tenant = Tenant::factory()->create(['custom_domain' => $domain, 'custom_domain_verified_at' => now()]);
    $tenant->forceFill(['custom_domain_token' => $token])->save();

    return $tenant->refresh();
}

/**
 * The sweep's audit rows, read scope-free. AuditLog carries the fail-closed
 * tenant scope, and a console run resolves no tenant, so a scoped query would
 * see no rows at all, and "nothing reported" would pass however much was.
 *
 * @return Builder<AuditLog>
 */
function failedDomainChecks(): Builder
{
    return AuditLog::withoutGlobalScopes()->where('action', 'platform.tenant.domain_check_failed');
}

beforeEach(fn () => config(['app.domain' => 'ethr.et', 'tenancy.custom_domain_target' => null]));

it('reports a verified domain whose records no longer check out, and keeps it verified', function () {
    $tenant = verifiedDomainTenant('hr.acme.com', 'abc');
    recheckDns(cname: ['hr.acme.com' => 'elsewhere.example']);
    Log::spy();

    $this->artisan('tenancy:recheck-custom-domains')->assertSuccessful();

    $row = failedDomainChecks()->sole();
    expect($row->payload)->toMatchArray([
        'domain' => 'hr.acme.com',
        'checks' => ['txt' => false, 'cname' => false],
    ])->and($tenant->fresh()->hasVerifiedCustomDomain())->toBeTrue();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'hr.acme.com'))->once();
});

it('reports nothing for a domain that still checks out', function () {
    verifiedDomainTenant('hr.acme.com', 'abc');
    recheckDns(
        txt: ['_ethr-verification.hr.acme.com' => ['ethr-verification=abc']],
        cname: ['hr.acme.com' => 'ethr.et'],
    );

    $this->artisan('tenancy:recheck-custom-domains')->assertSuccessful();

    expect(failedDomainChecks()->count())->toBe(0);
});

it('leaves pending domains to the Verify button', function () {
    Tenant::factory()->create(['custom_domain' => 'hr.pending.com']);
    recheckDns();

    $this->artisan('tenancy:recheck-custom-domains')->assertSuccessful();

    expect(failedDomainChecks()->count())->toBe(0);
});

it('runs weekly from the scheduler, without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains((string) $event->command, 'tenancy:recheck-custom-domains'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 3 * * 1')
        ->and($event->withoutOverlapping)->toBeTrue();
});
