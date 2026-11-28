<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\DeviceAdapter;
use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Jobs\PullDeviceEventsJob;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Attendance\AttendanceEngine;
use App\Services\CurrentTenant;
use App\Services\Device\DeviceManager;
use App\Services\Device\ZktecoAdapter;
use App\Services\Identity\IdentityResolver;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * One person, one working day, and the ways a terminal's events reach ETHR,
 * for tests/Feature/Idempotency. Static so no global function name can collide
 * with another test file.
 */
final class IdempotencyAttendanceFixtures
{
    public const ZONE = 'Africa/Addis_Ababa';

    /** A Tuesday. */
    public const DAY = '2026-10-13';

    public const BADGE = '8101';

    public const CODE = 'IDEM-1';

    /**
     * @return array{tenant: Tenant, branch: Branch, employee: Employee, hr: User, in: Carbon, out: Carbon}
     */
    public static function fixture(): array
    {
        $in = Carbon::parse(self::DAY.' 08:00', self::ZONE)->utc();
        $out = Carbon::parse(self::DAY.' 17:00', self::ZONE)->utc();
        Carbon::setTestNow($out->copy()->addMinutes(30));

        $tenant = createTenant(['timezone' => self::ZONE]);
        $shift = Shift::factory()->create([
            'tenant_id' => $tenant->id,
            'start_time' => '08:00',
            'end_time' => '17:00',
            'crosses_midnight' => false,
            'working_days' => '1,2,3,4,5',
            'grace_minutes' => 15,
            'early_departure_minutes' => 15,
        ]);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'employee_code' => self::CODE,
            'badge_number' => self::BADGE,
            'hire_date' => '2024-01-01',
        ]);
        ShiftAssignment::factory()->create([
            'tenant_id' => $tenant->id,
            'shift_id' => $shift->id,
            'assignable_type' => Employee::class,
            'assignable_id' => $employee->id,
        ]);

        return [
            'tenant' => $tenant,
            'branch' => $branch,
            'employee' => $employee,
            'hr' => createUser(['role' => UserRole::HR_ADMIN], $tenant),
            'in' => $in,
            'out' => $out,
        ];
    }

    public static function url(Tenant $tenant, string $path): string
    {
        return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
    }

    public static function local(Carbon $moment, string $format = 'Y-m-d H:i:s'): string
    {
        return $moment->copy()->setTimezone(self::ZONE)->format($format);
    }

    /** A ZKTeco terminal that is also pulled: one row, one id, both channels. */
    public static function zktecoDevice(array $ctx): Device
    {
        return Device::factory()->create([
            'tenant_id' => $ctx['tenant']->id,
            'branch_id' => $ctx['branch']->id,
            'adapter_type' => 'zkteco',
            'serial_number' => 'IDEM-ZK',
            'webhook_token' => 'idem-zkteco-token',
            'last_sync_at' => null,
        ]);
    }

    /** @return array<string, mixed> The body a ZKTeco terminal pushes for the day. */
    public static function zktecoPush(array $ctx): array
    {
        return ['records' => [
            ['pin' => self::BADGE, 'timestamp' => self::local($ctx['in']), 'punch' => 0],
            ['pin' => self::BADGE, 'timestamp' => self::local($ctx['out']), 'punch' => 1],
        ]];
    }

    /**
     * A pull adapter that returns the day's two events every time it is asked,
     * whatever `$since` says, and counts its calls.
     */
    public static function bindPullAdapter(array $ctx): object
    {
        $fake = new class implements DeviceAdapter
        {
            public int $pulls = 0;

            /** @var list<array{employee_badge: string, timestamp: string, type: string}> */
            public array $events = [];

            public function connect(Device $device): bool
            {
                return true;
            }

            public function getStatus(Device $device): array
            {
                return ['online' => true];
            }

            public function getDeviceInfo(Device $device): array
            {
                return [];
            }

            public function pullEvents(Device $device, ?string $since = null): array
            {
                $this->pulls++;

                return $this->events;
            }

            public function pullEnrollments(Device $device): array
            {
                return [];
            }

            public function pushEventUrl(Device $device, string $callbackUrl): bool
            {
                return false;
            }
        };

        foreach (['check_in' => $ctx['in'], 'check_out' => $ctx['out']] as $type => $at) {
            $fake->events[] = ['employee_badge' => self::BADGE, 'timestamp' => $at->copy()->setTimezone(self::ZONE)->format('Y-m-d\TH:i:sP'), 'type' => $type];
        }
        app()->instance(ZktecoAdapter::class, $fake);

        return $fake;
    }

    public static function pull(Device $device): void
    {
        (new PullDeviceEventsJob($device->id, $device->tenant_id, 'manual'))->handle(
            app(DeviceManager::class),
            app(AttendanceEngine::class),
            app(IdentityResolver::class),
        );
    }

    /** @return Collection<int, AttendanceRecord> Every row for the person, voided ones included. */
    public static function rows(Employee $employee): Collection
    {
        app(CurrentTenant::class)->set(Tenant::query()->findOrFail($employee->tenant_id));

        return AttendanceRecord::query()->where('employee_id', $employee->id)->orderBy('id')->get();
    }

    /** @return Collection<int, AttendanceRecord> */
    public static function live(Employee $employee): Collection
    {
        return self::rows($employee)->filter(fn (AttendanceRecord $r) => $r->status !== AttendanceStatus::VOIDED)->values();
    }
}
