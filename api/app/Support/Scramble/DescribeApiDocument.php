<?php

declare(strict_types=1);

namespace App\Support\Scramble;

use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Server;
use Dedoc\Scramble\Support\Generator\Tag;

/**
 * Document-level polish: what each domain means, and which host to call.
 *
 * Tag order here is the order the reference renders in, so it runs roughly in the
 * sequence an integrator meets the API — authenticate, read people, then the
 * operational and financial surfaces, then platform administration last.
 */
class DescribeApiDocument
{
    /**
     * Tag name => description. Order is significant.
     *
     * @var array<string, string>
     */
    private const TAGS = [
        'Public' => 'Unauthenticated endpoints: health checks, the plan catalogue, and the contact form.',
        'Authentication' => 'Sign-in, token refresh, MFA, SSO, and password reset. Every other endpoint requires the bearer token these return.',
        'Employees' => 'Employee records and their sub-resources — bank details, education, emergency contacts, documents, and the searchable directory.',
        'Users & Access' => 'Portal user accounts, invitations, custom roles, and the signed-in user\'s own profile.',
        'Organization' => 'Structure the workforce hangs off: branches, departments, positions, grades, teams, and cost centres.',
        'Attendance' => 'Attendance capture across every source — biometric devices, kiosk, QR, mobile, manual entry, and offline sync — plus corrections and conflict resolution.',
        'Shifts & Schedules' => 'Shift definitions, rosters, assignments, and the holiday calendar (Gregorian and Ethiopian).',
        'Devices' => 'Biometric device registration, enrolment discovery, sync, and health.',
        'Leave' => 'Leave types, balances, requests, and the approval workflow.',
        'Payroll' => 'Payroll runs and the rules behind them: tax brackets, overtime rates, allowances, loans, and accounting exports.',
        'Billing' => 'Subscription plans, invoices, and payment status for the tenant.',
        'Dashboards & Reporting' => 'Aggregated views for employees, managers, and executives, plus the report builder and exports.',
        'Notifications' => 'In-app notifications, delivery preferences, and company announcements.',
        'Onboarding' => 'Guided tenant setup: industry templates, configuration, data migration, and go-live readiness.',
        'Settings' => 'Tenant configuration, branding, notification templates, and the immutable audit log.',
        'Integrations' => 'Outbound webhooks, API keys, and SCIM 2.0 user and group provisioning.',
        'Platform Administration' => 'Super-admin only: tenant lifecycle, platform health, failed jobs, revenue, and platform-wide settings such as the bank account tenants pay into.',
    ];

    public function __invoke(OpenApi $document): void
    {
        $document->servers = $this->servers();

        // Scramble emits tags as encountered; declaring them here fixes the order
        // and attaches the prose. Anything the operation transformer produced that
        // is not declared below still renders, just after these.
        $document->tags = array_map(
            fn (string $name, string $description) => new Tag($name, $description),
            array_keys(self::TAGS),
            array_values(self::TAGS),
        );
        file_put_contents(storage_path('probe.txt'), 'after: '.count($document->tags).' oid='.spl_object_id($document).' class='.get_class($document).'
', FILE_APPEND);
    }

    /**
     * @return list<Server>
     */
    private function servers(): array
    {
        $servers = [];

        // Production first so a reader copying the first URL hits the right host.
        // Previously the only server was whatever APP_URL happened to be locally,
        // so a published spec told integrators to call http://localhost:8000.
        if ($production = config('scramble.production_url')) {
            $servers[] = Server::make($production)->setDescription('Production');
        }

        $servers[] = Server::make(rtrim((string) config('app.url'), '/').'/api/v1')
            ->setDescription('Local development');

        return $servers;
    }
}
