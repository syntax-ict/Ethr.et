<?php

declare(strict_types=1);

namespace Tests\Accuracy\Leave;

use App\Enums\AccrualType;
use App\Enums\LeaveStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRotation;
use App\Models\ShiftRotationStep;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Approval\ApprovalEngine;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeaveRecallService;
use App\Services\Leave\LeaveYear;
use Carbon\Carbon;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Builds the world a leave case describes (an organisation, a leave year, one
 * leave type, one person, holidays, balances, requests) and carries out its
 * acts. It states the case's facts and calls the engine's own entry points; it
 * implements no leave rule and computes no expected value.
 *
 * Every field the model factories would otherwise randomise or default
 * (a type's accrual, notice and code, a person's gender and hire date, a
 * holiday's recurrence) is set explicitly here.
 */
final class LeaveFixture
{
    /**
     * @param  array<string, mixed>  $case  merged over LeaveCases::DEFAULTS
     * @return array<string, mixed>
     */
    public static function build(array $case, TestCase $t): array
    {
        Carbon::setTestNow(Carbon::parse($case['now'], 'UTC'));

        $org = $case['org'];
        $tenant = \createTenant([
            'timezone' => 'Africa/Addis_Ababa',
            'settings' => ['leave_year' => $org['leave_year'], 'working_days' => $org['working_week']],
        ]);
        $own = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $other = Branch::factory()->create(['tenant_id' => $tenant->id]);

        foreach ($org['holidays'] as $holiday) {
            self::holiday($tenant, $holiday, $own, $other);
        }

        $type = self::type($tenant, $case['type']);

        $e = $case['employee'];
        // The column is NOT NULL: a person with no hire date (LVR-ENT-08) exists only unsaved.
        $employee = Employee::factory()->{$e['hire_date'] === null ? 'make' : 'create'}([
            'tenant_id' => $tenant->id,
            'branch_id' => $own->id,
            'hire_date' => $e['hire_date'],
            'termination_date' => $e['termination_date'],
            'gender' => $e['gender'],
            'status' => 'confirmed',
        ]);

        $ctx = [
            'case' => $case, 't' => $t, 'tenant' => $tenant, 'type' => $type, 'employee' => $employee,
            'own' => $own, 'other' => $other, 'users' => [], 'requests' => [], 'shifts' => [],
        ];

        self::schedule($ctx, $e);

        foreach ($case['balances'] as $row) {
            LeaveBalance::query()->create([
                'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id,
                'year' => $row['year'],
                'entitled_days' => $row['entitled_tenths'] / 10, 'used_days' => $row['used_tenths'] / 10,
                'carried_days' => $row['carried_tenths'] / 10, 'pending_days' => $row['pending_tenths'] / 10,
            ]);
        }

        foreach ($case['requests'] as $row) {
            $ctx = self::storedRequest($ctx, $row);
        }

        return $ctx;
    }

    /**
     * @param  array<string, mixed>  $holiday
     */
    private static function holiday(Tenant $tenant, array $holiday, Branch $own, Branch $other): Holiday
    {
        return Holiday::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => match ($holiday['branch']) {
                null => null, 'own' => $own->id, 'other' => $other->id,
                default => throw new InvalidArgumentException('holiday branch is null, own or other'),
            },
            'date' => $holiday['date'],
            'recurring' => $holiday['recurring'],
            'is_active' => $holiday['active'],
            'ethiopian_calendar' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $t
     */
    private static function type(Tenant $tenant, array $t): LeaveType
    {
        return LeaveType::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => $t['code'], 'code' => $t['code'], 'kind' => LeaveType::KIND_LEAVE,
            'default_days' => $t['default_days'],
            'accrual_type' => AccrualType::from($t['accrual']),
            'carry_forward' => $t['carry_forward'], 'max_carry_days' => $t['max_carry_days'],
            'seniority_every_years' => $t['seniority_every_years'], 'seniority_extra_days' => $t['seniority_extra_days'],
            'requires_approval' => true, 'requires_attachment' => false,
            'min_notice_days' => $t['min_notice_days'], 'max_consecutive' => $t['max_consecutive_days'],
            'counts_calendar_days' => $t['counts_calendar_days'], 'follows_art85' => $t['follows_art85'],
            'is_paid' => $t['is_paid'], 'is_active' => true, 'gender_restriction' => $t['gender'],
        ]);
    }

    /**
     * The person's schedule: a shift, a list of dated assignments, or a rotation.
     *
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $e
     */
    private static function schedule(array &$ctx, array $e): void
    {
        $tenant = $ctx['tenant'];
        $employee = $ctx['employee'];
        $assign = fn (array $attributes) => ShiftAssignment::factory()->create([
            'tenant_id' => $tenant->id, 'assignable_type' => Employee::class, 'assignable_id' => $employee->id,
            'effective_from' => '2024-01-01', 'effective_to' => null, ...$attributes,
        ]);

        if ($e['shift'] !== null) {
            $assign(['shift_id' => self::shift($ctx, $e['shift'])->id]);
        }
        foreach ($e['assignments'] ?? [] as $a) {
            $assign(['shift_id' => self::shift($ctx, $a['shift'])->id, 'effective_from' => $a['from'], 'effective_to' => $a['to'] ?? null]);
        }
        if ($e['rotation'] !== null) {
            $steps = $e['rotation']['steps'];
            $rotation = ShiftRotation::factory()->create([
                'tenant_id' => $tenant->id, 'name' => 'Leave rotation', 'cycle_days' => count($steps),
                'anchor_date' => $e['rotation']['anchor'], 'is_active' => true, 'is_default' => false,
            ]);
            foreach ($steps as $offset => $name) {
                // A step with no shift is left out: an offset with no step is a rest day.
                if ($name !== null) {
                    ShiftRotationStep::query()->create([
                        'tenant_id' => $tenant->id, 'shift_rotation_id' => $rotation->id,
                        'day_offset' => $offset, 'shift_id' => self::shift($ctx, $name)->id,
                    ]);
                }
            }
            $assign(['shift_id' => null, 'shift_rotation_id' => $rotation->id, 'anchor_date' => $e['rotation']['anchor']]);
        }
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private static function shift(array &$ctx, string $name): Shift
    {
        $shifts = require __DIR__.'/fixtures/shifts.php';
        $ctx['shifts'][$name] ??= Shift::factory()->create([
            'tenant_id' => $ctx['tenant']->id,
            ...($shifts[$name] ?? throw new InvalidArgumentException("Unknown leave shift {$name}")),
        ]);

        return $ctx['shifts'][$name];
    }

    /**
     * A request that already exists when the case starts.
     *
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function storedRequest(array $ctx, array $row): array
    {
        $request = LeaveRequest::factory()->create([
            'tenant_id' => $ctx['tenant']->id, 'employee_id' => $ctx['employee']->id, 'leave_type_id' => $ctx['type']->id,
            'start_date' => $row['from'], 'end_date' => $row['to'], 'days' => $row['days'],
            'status' => LeaveStatus::from($row['status']), 'approved_by' => [],
        ]);
        if (($row['filed_by'] ?? 'employee') !== 'employee') {
            app(ApprovalEngine::class)->recordFiledFor($request, self::user($ctx, $row['filed_by']));
        }
        $ctx['requests'][$row['key']] = $request;

        return $ctx;
    }

    /**
     * The account an act is made as: the employee's own, or an administrator.
     *
     * @param  array<string, mixed>  $ctx
     */
    public static function user(array &$ctx, string $actor): User
    {
        $tenant = $ctx['tenant'];

        return $ctx['users'][$actor] ??= match ($actor) {
            'employee' => \createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $ctx['employee']->id], $tenant),
            'hr', 'hr2' => \createUser(['role' => UserRole::HR_ADMIN], $tenant),
            default => throw new InvalidArgumentException("Unknown actor {$actor}"),
        };
    }

    /**
     * Carries out one act and says what came of it.
     *
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $act
     * @return array<string, mixed> refused (bool), kept_days (float|null)
     */
    public static function act(array &$ctx, array $act): array
    {
        if (isset($act['at'])) {
            Carbon::setTestNow(Carbon::parse($act['at'], 'UTC'));
        }
        $tenant = $ctx['tenant'];
        $result = ['refused' => false, 'kept_days' => null];
        $service = app(LeaveBalanceService::class);

        switch ($act['do']) {
            case 'accrue':
                $service->accrueMonthly($tenant->id);
                // An annual or one-time type is not accrued: its balance is created, with the
                // whole entitlement, the first time the year is read or filed against.
                if ($ctx['type']->accrual_type !== AccrualType::MONTHLY) {
                    $leaveYear = LeaveYear::forTenantId($tenant->id);
                    $service->getOrCreateBalance($ctx['employee'], $ctx['type'], $leaveYear->yearOf($leaveYear->today()), $leaveYear);
                }
                break;
            case 'carry':
                $service->carryForward($tenant->id, $act['from'], $act['to']);
                break;
            case 'terminate':
                $ctx['employee']->update(['termination_date' => $act['date']]);
                break;
            case 'add_holiday':
                self::holiday($tenant, [...LeaveCases::DEFAULTS['holiday'], 'date' => $act['date']], $ctx['own'], $ctx['other']);
                break;
            case 'file':
                $payload = [
                    'leave_type_public_id' => $ctx['type']->public_id,
                    'start_date' => $act['from'], 'end_date' => $act['to'], 'reason' => 'accuracy case',
                ];
                if ($act['by'] !== 'employee') {
                    $payload['employee_public_id'] = $ctx['employee']->public_id;
                }
                $response = self::send($ctx, $act['by'], 'POST', 'request', $payload);
                $result['refused'] = $response->getStatusCode() >= 400;
                if (! $result['refused']) {
                    $ctx['requests'][$act['key']] = LeaveRequest::query()->where('public_id', $response->json('public_id'))->firstOrFail();
                }
                break;
            case 'approve':
            case 'reject':
                $response = self::send($ctx, $act['by'], 'PUT', "{$ctx['requests'][$act['key']]->public_id}/{$act['do']}", $act['do'] === 'reject' ? ['reason' => 'accuracy case'] : []);
                $result['refused'] = $response->getStatusCode() >= 400;
                break;
            case 'cancel':
                // The employee cancels their own pending request; HR ends approved leave through recall.
                $id = $ctx['requests'][$act['key']]->public_id;
                $response = $act['by'] === 'employee'
                    ? self::send($ctx, 'employee', 'PUT', "{$id}/cancel", [])
                    : self::send($ctx, $act['by'], 'PUT', "{$id}/recall", ['reason' => 'accuracy case']);
                $result['refused'] = $response->getStatusCode() >= 400;
                break;
            case 'recall':
                $request = $ctx['requests'][$act['key']];
                $result['kept_days'] = app(LeaveRecallService::class)->daysUntil($request->fresh(), Carbon::parse($act['end']));
                $response = self::send($ctx, $act['by'], 'PUT', "{$request->public_id}/recall", ['end_date' => $act['end'], 'reason' => 'accuracy case']);
                $result['refused'] = $response->getStatusCode() >= 400;
                break;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $payload
     */
    private static function send(array &$ctx, string $actor, string $method, string $path, array $payload): TestResponse
    {
        $user = self::user($ctx, $actor);
        $t = $ctx['t'];
        $url = "http://{$ctx['tenant']->subdomain}.ethr.test/api/v1/leave/{$path}";

        return $t->actingAs($user)->json($method, $url, $payload);
    }

    /**
     * The balance row of a leave year as tenths of a day. A year with no row
     * reads as zero.
     *
     * @param  array<string, mixed>  $ctx
     * @return array<string, int>
     */
    public static function balance(array $ctx, int $year): array
    {
        $row = LeaveBalance::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $ctx['tenant']->id)
            ->where('employee_id', $ctx['employee']->id)
            ->where('leave_type_id', $ctx['type']->id)
            ->where('year', $year)
            ->first();

        $tenths = fn (float $days): int => (int) round($days * 10);

        return $row === null
            ? ['entitled_tenths' => 0, 'used_tenths' => 0, 'carried_tenths' => 0, 'pending_tenths' => 0, 'remaining_tenths' => 0]
            : [
                'entitled_tenths' => $tenths((float) $row->entitled_days),
                'used_tenths' => $tenths((float) $row->used_days),
                'carried_tenths' => $tenths((float) $row->carried_days),
                'pending_tenths' => $tenths((float) $row->pending_days),
                'remaining_tenths' => $tenths($row->remainingDays()),
            ];
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    public static function leaveYear(array $ctx): LeaveYear
    {
        return LeaveYear::forTenantId($ctx['tenant']->id);
    }
}
