<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\ApplicationStage;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\JobApplication;
use App\Models\SsoSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\PlanLimitService;
use App\Services\Sso\SsoProviderInterface;
use App\Services\Sso\SsoUser;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/**
 * What the employee-intake tests need to reach each way an employee is made:
 * a SCIM token, an SSO sign-in that yields a chosen identity, an application
 * ready to hire, and a watch on the seat lock.
 *
 * A class, not helper functions: Pest's helper functions are global across
 * every test file, and a second file declaring the same name makes collection
 * fatal, which the gate reports as "0 test classes collected".
 */
final class IntakeFixtures
{
    /** A bearer token the SCIM endpoints accept for this organisation. */
    public static function scimToken(Tenant $tenant): string
    {
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::TENANT_ADMIN]);
        $token = 'scim-intake-'.bin2hex(random_bytes(8));

        ApiKey::create([
            'tenant_id' => $tenant->id,
            'name' => 'SCIM intake',
            'key_hash' => hash('sha256', $token),
            'key_prefix' => substr($token, 0, 8),
            'abilities' => ['scim'],
            'created_by' => $admin->id,
            'expires_at' => now()->addYear(),
        ]);

        return $token;
    }

    /**
     * Switch SSO on for the organisation, with auto-provisioning, and make the
     * next assertion the identity provider sends be `$identity`. The SAML
     * cryptography is SsoSamlSignatureTest's; this is about what happens after.
     */
    public static function ssoSignsIn(Tenant $tenant, SsoUser $identity): string
    {
        SsoSetting::create([
            'tenant_id' => $tenant->id,
            'provider' => 'saml',
            'is_enabled' => true,
            'idp_entity_id' => 'https://idp.example.test',
            'idp_sso_url' => 'https://idp.example.test/sso',
            'idp_certificate' => 'unused-by-the-double',
            'auto_provision' => true,
        ]);

        app()->instance(SsoProviderInterface::class, new class($identity) implements SsoProviderInterface
        {
            public function __construct(private SsoUser $identity) {}

            public function getLoginUrl(Tenant $tenant, string $relayState = ''): string
            {
                return 'https://idp.example.test/sso';
            }

            public function handleCallback(Tenant $tenant, array $requestData): SsoUser
            {
                return $this->identity;
            }

            public function getMetadataXml(Tenant $tenant): string
            {
                return '<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata"/>';
            }

            public function isConfigured(Tenant $tenant): bool
            {
                return true;
            }
        });

        return "/api/v1/sso/saml/{$tenant->subdomain}/acs";
    }

    /** An application with an approved offer, ready to hire. */
    public static function approvedOffer(Tenant $tenant, User $approver, array $attributes = []): JobApplication
    {
        $vacancy = Vacancy::query()->create([
            'tenant_id' => $tenant->id,
            'title' => 'Payroll officer',
            'employment_type' => 'full_time',
            'description' => 'Run monthly payroll.',
            'status' => 'open',
            'published_at' => now()->subDay(),
        ]);

        return JobApplication::query()->create([
            'tenant_id' => $tenant->id,
            'vacancy_id' => $vacancy->id,
            'name' => 'Hanna Tesfaye',
            'email' => 'hanna@example.et',
            'phone' => '+251911223344',
            'source' => 'email',
            'stage' => ApplicationStage::OFFER,
            'offer_start_date' => now()->addWeek()->toDateString(),
            'offer_made_by' => User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::HR_ADMIN])->id,
            'offer_approved_by' => $approver->id,
            'offer_approved_at' => now(),
            ...$attributes,
        ]);
    }

    /**
     * Watch the organisation's seat lock from now on. `asked` gets one entry
     * each time IdentityResolver looks for candidates and `made` one each time
     * an employee row is inserted; the entry is whether the lock was held at
     * that moment.
     *
     * The question is recognised by the one clause only its query has
     * (`deleted_at IS NULL DESC`, current employees before deleted ones).
     * IdentityResolver is final, so it cannot be doubled, and a double would
     * prove less: this watches the statements the database was sent.
     */
    public static function watchSeatLock(Tenant $tenant): stdClass
    {
        $seen = new stdClass;
        $seen->asked = [];
        $seen->made = [];

        $key = PlanLimitService::lockKey($tenant, 'employees');
        $held = static function () use ($key): bool {
            $probe = Cache::lock($key, 5);
            if ($probe->get()) {
                $probe->release();

                return false;
            }

            return true;
        };

        DB::listen(static function (QueryExecuted $query) use ($seen, $held): void {
            if (str_contains($query->sql, 'deleted_at IS NULL DESC')) {
                $seen->asked[] = $held();
            } elseif (preg_match('/^insert into [`"]employees[`"]/i', $query->sql) === 1) {
                $seen->made[] = $held();
            }
        });

        return $seen;
    }

    /** Guard for a test that would otherwise pass by observing nothing. */
    public static function assertWatched(stdClass $seen): void
    {
        if ($seen->asked === [] || $seen->made === []) {
            throw new RuntimeException('The seat-lock watch saw no question or no insert; the test proves nothing.');
        }
    }
}
