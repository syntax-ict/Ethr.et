<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PlanFeature;
use App\Enums\TenantAddressMode;
use App\Models\AuditLog;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantSubdomainAlias;
use App\Support\FrontendUrl;
use App\Support\TenancyDomain;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An organisation's address: its name (the slug), the mode it chose, its
 * custom domain and that domain's verification. One implementation behind two doors, the platform
 * console (AdminTenantController) and the organisation's own settings
 * (SettingsController), so neither can drift from the other on what a
 * domain is, when it counts, or what the plan allows.
 */
final class TenantAddressService
{
    public function __construct(
        private readonly PlanFeatureService $plans,
        private readonly CustomDomainVerifier $verifier,
    ) {}

    /**
     * Everything a screen needs to show and change the address.
     *
     * @return array{
     *     mode: string,
     *     effective_mode: string,
     *     canonical_url: string|null,
     *     entry_url: string,
     *     subdomain: string,
     *     subdomain_url: string|null,
     *     retired_subdomains: list<string>,
     *     subdomains_served: bool,
     *     custom_domain: string|null,
     *     custom_domain_status: string|null,
     *     custom_domain_allowed: bool,
     *     custom_domain_dns: array{txt_name: string, txt_value: string, cname_name: string, cname_target: string|null}|null
     * }
     */
    public function describe(Tenant $tenant): array
    {
        $this->loadSubscription($tenant);
        $root = TenancyDomain::root();

        return [
            'mode' => $tenant->addressMode()->value,
            'effective_mode' => $tenant->effectiveAddressMode()->value,
            'canonical_url' => FrontendUrl::canonicalOrigin($tenant),
            'entry_url' => FrontendUrl::to($tenant->subdomain),
            'subdomain' => $tenant->subdomain,
            'subdomain_url' => $root === null ? null : $this->hostedOrigin("{$tenant->subdomain}.{$root}"),
            'retired_subdomains' => $tenant->retiredSubdomains(),
            'subdomains_served' => Tenant::subdomainsServed(),
            'custom_domain' => $tenant->custom_domain,
            'custom_domain_status' => $this->domainStatus($tenant),
            'custom_domain_allowed' => $this->plans->allows($tenant, PlanFeature::CustomDomain),
            'custom_domain_dns' => $this->domainDns($tenant),
        ];
    }

    /**
     * Record the organisation's choice. A domain given alongside is assigned
     * first, so choosing `custom_domain` and naming the domain is one save.
     */
    public function changeMode(Tenant $tenant, TenantAddressMode $mode, string $auditAction): void
    {
        $old = $tenant->addressMode();
        $tenant->update(['address_mode' => $mode]);

        AuditLog::record($auditAction, $tenant, [
            'old_mode' => $old->value,
            'new_mode' => $mode->value,
            'effective_mode' => $tenant->effectiveAddressMode()->value,
        ]);
    }

    /**
     * Rename the organisation's address: ethr.et/{old} becomes ethr.et/{new},
     * and {old}.ethr.et becomes {new}.ethr.et. A custom domain is untouched.
     *
     * The old name is retired, not released. It keeps resolving to this
     * organisation (Tenant::findBySlug), which forwards it, so every link
     * already sent and every browser still holding the old name keeps
     * working, and no other organisation can take it and receive them. A
     * name this organisation retired before is simply taken back. Format and
     * availability are the request's rule (UpdateSubdomainRequest); this
     * checks availability again inside the transaction, so two organisations
     * racing for one name cannot both win.
     *
     * @throws ValidationException under `subdomain` when the name is not available
     */
    public function changeSubdomain(Tenant $tenant, string $subdomain, string $auditAction): void
    {
        $old = $tenant->subdomain;
        if ($subdomain === $old) {
            return;
        }

        DB::transaction(function () use ($tenant, $subdomain, $old): void {
            if (! Tenant::slugAvailableFor($subdomain, $tenant)) {
                throw ValidationException::withMessages([
                    'subdomain' => [__('validation.subdomain_taken')],
                ]);
            }

            // Taking back a name it retired: that row goes, and the name is
            // the address again. Stated by tenant, because the console door
            // asks about organisations other than the one resolved.
            TenantSubdomainAlias::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('subdomain', $subdomain)
                ->delete();

            TenantSubdomainAlias::query()->create([
                'tenant_id' => $tenant->id,
                'subdomain' => $old,
            ]);

            $tenant->update(['subdomain' => $subdomain]);
        });

        // The saved hook clears the old and new names' resolver caches; names
        // retired before then may still hold this model with its last name.
        foreach ($tenant->retiredSubdomains() as $retired) {
            Cache::forget("tenant:{$retired}");
        }

        AuditLog::record($auditAction, $tenant, [
            'old_subdomain' => $old,
            'new_subdomain' => $subdomain,
        ]);
    }

    /**
     * Assign, change or clear the custom domain. A new domain is stored
     * PENDING with a fresh token (Tenant's saving hook): it resolves nothing
     * and no link uses it until Verify finds the DNS records. Clearing never
     * needs the add-on; assigning does.
     *
     * @throws ValidationException when the plan does not include the add-on
     */
    public function assignDomain(Tenant $tenant, ?string $domain, string $auditAction): void
    {
        $this->loadSubscription($tenant);

        if ($domain !== null && ! $this->plans->allows($tenant, PlanFeature::CustomDomain)) {
            throw ValidationException::withMessages([
                'custom_domain' => [__('validation.custom_domain_not_in_plan')],
            ]);
        }

        $old = $tenant->custom_domain;
        $tenant->update(['custom_domain' => $domain]);

        AuditLog::record($auditAction, $tenant, [
            'old_domain' => $old,
            'new_domain' => $tenant->custom_domain,
        ]);
    }

    /**
     * Check the pending domain's TXT token and CNAME, and make it the
     * organisation's address when both are in place. A domain already
     * verified is left as it is: a DNS hiccup at the moment someone presses
     * Verify must not take an address away. Changing the domain is what
     * starts verification over.
     *
     * @throws ValidationException with one message per record not found, under `txt` and `cname`
     */
    public function verifyDomain(Tenant $tenant, string $auditAction): void
    {
        if ($tenant->custom_domain === null) {
            throw ValidationException::withMessages([
                'custom_domain' => [__('validation.custom_domain_none')],
            ]);
        }

        if ($tenant->hasVerifiedCustomDomain()) {
            return;
        }

        $errors = $this->failedDnsChecks($tenant, $this->verifier->check($tenant));
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $tenant->forceFill(['custom_domain_verified_at' => now()])->save();

        AuditLog::record($auditAction, $tenant, ['domain' => $tenant->custom_domain]);
    }

    /** `null` with no domain, `pending` until Verify passes, then `verified`. */
    public function domainStatus(Tenant $tenant): ?string
    {
        if ($tenant->custom_domain === null) {
            return null;
        }

        return $tenant->hasVerifiedCustomDomain() ? 'verified' : 'pending';
    }

    /**
     * The two DNS records the organisation publishes, or null with no domain.
     *
     * @return array{txt_name: string, txt_value: string, cname_name: string, cname_target: string|null}|null
     */
    public function domainDns(Tenant $tenant): ?array
    {
        $domain = $tenant->custom_domain;
        if ($domain === null) {
            return null;
        }

        return [
            'txt_name' => CustomDomainVerifier::txtName($domain),
            'txt_value' => CustomDomainVerifier::txtValue((string) $tenant->custom_domain_token),
            'cname_name' => $domain,
            'cname_target' => CustomDomainVerifier::target(),
        ];
    }

    /**
     * One message per DNS check that did not pass, keyed `txt` and `cname`.
     *
     * @param  array{txt: bool, cname: bool}  $checks
     * @return array<string, list<string>>
     */
    public function failedDnsChecks(Tenant $tenant, array $checks): array
    {
        $dns = $this->domainDns($tenant);
        $errors = [];

        if (! $checks['txt'] && $dns !== null) {
            $errors['txt'] = [__('validation.custom_domain_txt_missing', [
                'name' => $dns['txt_name'],
                'value' => $dns['txt_value'],
            ])];
        }

        if (! $checks['cname'] && $dns !== null) {
            $errors['cname'] = [__('validation.custom_domain_cname_missing', [
                'name' => $dns['cname_name'],
                'target' => (string) $dns['cname_target'],
            ])];
        }

        return $errors;
    }

    /**
     * The plan gate reads `$tenant->subscription->plan`; the relation is the
     * latest subscription, loaded once, bypassing the tenant scope because the
     * console asks about tenants other than the current one.
     */
    private function loadSubscription(Tenant $tenant): void
    {
        if ($tenant->relationLoaded('subscription')) {
            return;
        }

        $tenant->setRelation('subscription', Subscription::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->with('plan')
            ->latest()
            ->first());
    }

    /** The frontend's scheme and port around a hostname, as canonicalOrigin builds them. */
    private function hostedOrigin(string $host): string
    {
        $frontend = parse_url((string) config('app.frontend_url'));
        $scheme = $frontend['scheme'] ?? 'https';
        $port = isset($frontend['port']) ? ':'.$frontend['port'] : '';

        return "{$scheme}://{$host}{$port}";
    }
}
