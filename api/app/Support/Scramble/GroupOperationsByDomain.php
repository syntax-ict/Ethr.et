<?php

declare(strict_types=1);

namespace App\Support\Scramble;

use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\Str;

/**
 * Makes the generated API reference navigable and self-describing.
 *
 * Out of the box Scramble tags each operation with its controller's short name.
 * That produced 83 tags for 310 operations — 18 of them holding a single endpoint
 * — so the reference read as 83 disconnected fragments with no relationship to how
 * the product is actually organised. It also left 307 of 310 operations with no
 * summary at all, because summaries come from controller docblocks and most
 * methods have none.
 *
 * This transformer does two things:
 *
 *  1. Collapses controller-derived tags into the product's own domains (the same
 *     grouping as the sidebar in CLAUDE.md), so the reference mirrors the mental
 *     model a reader already has.
 *
 *  2. Supplies a summary derived from the HTTP method and resource when the
 *     controller has no docblock. A generated summary is weaker than a written
 *     one, but "List employees" beats a blank line, and a real docblock always
 *     wins — this only fills gaps, it never overwrites.
 */
class GroupOperationsByDomain
{
    /**
     * Controller-name prefix => product domain. Longest match wins, so
     * `AttendanceSetting` resolves before `Attendance`.
     *
     * @var array<string, string>
     */
    private const DOMAIN_BY_CONTROLLER = [
        // Platform administration — super admin only.
        'AdminDashboard' => 'Platform Administration',
        'AdminTenant' => 'Platform Administration',
        'PlatformSettings' => 'Platform Administration',

        // Authentication & identity.
        'Login' => 'Authentication',
        'Logout' => 'Authentication',
        'Refresh' => 'Authentication',
        'Register' => 'Authentication',
        'Me' => 'Authentication',
        'MfaSetup' => 'Authentication',
        'MfaVerify' => 'Authentication',
        'PasswordReset' => 'Authentication',
        'Sso' => 'Authentication',
        'SubdomainCheck' => 'Authentication',

        // People.
        'Employee' => 'Employees',
        'EmployeeAttendanceTimeline' => 'Attendance',
        'BankDetail' => 'Employees',
        'Education' => 'Employees',
        'EmergencyContact' => 'Employees',
        'Directory' => 'Employees',
        'User' => 'Users & Access',
        'CustomRole' => 'Users & Access',
        'Profile' => 'Users & Access',

        // Organisation structure.
        'Branch' => 'Organization',
        'Department' => 'Organization',
        'Position' => 'Organization',
        'Grade' => 'Organization',
        'Team' => 'Organization',
        'CostCenter' => 'Organization',

        // Attendance & time.
        'Attendance' => 'Attendance',
        'Kiosk' => 'Attendance',
        'Qr' => 'Attendance',
        'Mobile' => 'Attendance',
        'Manual' => 'Attendance',
        'Offline' => 'Attendance',
        'Shift' => 'Shifts & Schedules',
        'Holiday' => 'Shifts & Schedules',
        'Device' => 'Devices',

        // Leave.
        'Leave' => 'Leave',
        'Approval' => 'Leave',

        // Finance.
        'Payroll' => 'Payroll',
        'TaxBracket' => 'Payroll',
        'OvertimeRate' => 'Payroll',
        'Loan' => 'Payroll',
        'Accounting' => 'Payroll',
        'Billing' => 'Billing',
        'Plan' => 'Billing',

        // Insight.
        'Dashboard' => 'Dashboards & Reporting',
        'ExecutiveDashboard' => 'Dashboards & Reporting',
        'Analytics' => 'Dashboards & Reporting',
        'Report' => 'Dashboards & Reporting',

        // Configuration & platform surface.
        'Settings' => 'Settings',
        'AuditLog' => 'Settings',
        'NotificationTemplate' => 'Settings',
        'Notification' => 'Notifications',
        'Announcement' => 'Notifications',
        'Onboarding' => 'Onboarding',
        'Migration' => 'Onboarding',
        'Template' => 'Onboarding',
        'Webhook' => 'Integrations',
        'ApiKey' => 'Integrations',
        'Scim' => 'Integrations',
        'Contact' => 'Public',
        'Health' => 'Public',
    ];

    /**
     * Path fallback, consulted only when the controller name is unmapped.
     *
     * Covers two cases the controller map cannot: closure routes, which have no
     * controller at all (`/ping`, `/broadcasting/auth`), and sub-controllers whose
     * class names are too generic to map safely on their own — `ConfigurationController`,
     * `AccessController` and `ReadinessController` all live under `/onboarding`, and
     * mapping bare "Configuration" or "Access" by name would be a trap for whatever
     * unrelated controller is added with that name later.
     *
     * @var array<string, string>
     */
    private const DOMAIN_BY_PATH_PREFIX = [
        '/onboarding' => 'Onboarding',
        '/broadcasting' => 'Authentication',
        '/ping' => 'Public',
        '/health' => 'Public',
    ];

    public function __invoke(Operation $operation, RouteInfo $routeInfo): void
    {
        $controller = class_basename($routeInfo->className() ?? '');
        $controller = Str::replaceLast('Controller', '', $controller);

        $operation->setTags([$this->domainFor($controller, $operation->path)]);

        if (blank($operation->summary)) {
            $operation->summary($this->summaryFor($operation));
        }
    }

    private function domainFor(string $controller, string $path): string
    {
        $best = null;

        foreach (self::DOMAIN_BY_CONTROLLER as $prefix => $domain) {
            if (! str_starts_with($controller, $prefix)) {
                continue;
            }
            // Longest prefix wins so AttendanceSetting does not match Attendance.
            if ($best === null || strlen($prefix) > strlen($best[0])) {
                $best = [$prefix, $domain];
            }
        }

        if ($best !== null) {
            return $best[1];
        }

        // Matched on a segment boundary rather than str_starts_with: operation paths
        // still carry the `api/v1` prefix at transformer time — Scramble strips it
        // later, when it builds the Path objects — so an anchored prefix never hits.
        $normalizedPath = '/'.trim($path, '/').'/';
        foreach (self::DOMAIN_BY_PATH_PREFIX as $prefix => $domain) {
            if (str_contains($normalizedPath, rtrim($prefix, '/').'/')) {
                return $domain;
            }
        }

        // Still unmapped: keep the controller's own name rather than sweeping it into
        // a catch-all. A stray tag in the reference is a visible prompt to add it
        // here, whereas "Other" would quietly hide it.
        return $controller !== '' ? Str::headline($controller) : 'General';
    }

    /**
     * A readable summary derived from the route, for endpoints whose controller has
     * no docblock.
     *
     * Two shapes need different phrasing, and treating everything as CRUD produced
     * nonsense — `POST /attendance/check-in` came out as "Create check in" and
     * `GET /attendance/my` as "List my":
     *
     *   Resource routes  — the last segment is a collection or an id.
     *                      `GET /employees` → "List employees"
     *                      `PUT /employees/{employee}` → "Update employee"
     *   Action routes    — the last segment is a verb or qualifier.
     *                      `POST /attendance/check-in` → "Check in attendance"
     *                      `GET /attendance/my` → "My attendance"
     *
     * A written docblock always beats this; it only fills gaps.
     */
    private function summaryFor(Operation $operation): string
    {
        $segments = array_values(array_filter(explode('/', $operation->path)));
        $isItem = $segments !== [] && str_starts_with((string) end($segments), '{');

        $nouns = array_values(array_filter(
            $segments,
            fn (string $s) => ! str_starts_with($s, '{'),
        ));

        if ($nouns === []) {
            return 'Call endpoint';
        }

        $last = (string) end($nouns);
        $words = fn (string $s) => Str::lower(Str::headline(str_replace('-', ' ', $s)));

        // An id in the path, or a plural final segment, means the route addresses a
        // resource. Anything else is an action or qualifier on its parent.
        $isResource = $isItem || Str::endsWith($last, 's');

        if (! $isResource) {
            $parent = count($nouns) > 1 ? $words($nouns[count($nouns) - 2]) : '';

            return trim(Str::ucfirst($words($last).' '.$parent));
        }

        $verb = match (Str::upper($operation->method)) {
            'GET' => $isItem ? 'Get' : 'List',
            'POST' => 'Create',
            'PUT', 'PATCH' => 'Update',
            'DELETE' => 'Delete',
            default => 'Call',
        };

        // Only a collection GET is plural. Everything else acts on one record —
        // including POST to a collection, which creates a single thing.
        $resource = $verb === 'List' ? $words($last) : Str::singular($words($last));

        return "{$verb} {$resource}";
    }
}
