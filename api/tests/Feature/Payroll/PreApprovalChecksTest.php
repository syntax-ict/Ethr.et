<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\BackfillBankAccountHashesJob;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeBankDetail;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Services\Auth\ImpersonationToken;
use App\Services\Payroll\PayrollEngine;
use App\Services\Payroll\PreApprovalChecks;
use App\Services\Payroll\RunRegisterService;
use App\Support\PayChangeThresholds;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * What an approver is shown before releasing a run, found by rule
 * (2026-11-22): net pay that moved since the last released run, people paid
 * into one account, people paid with no TIN, people paid with no attendance
 * at all. None of it blocks approval or changes pay.
 */

function pacUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
}

function pacEmployee(Tenant $tenant, array $attributes = []): Employee
{
    return Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2024-01-01', 'tin' => '0012345678', ...$attributes]);
}

/**
 * A run of the given period with one entry per person, net => cents.
 *
 * @param  array<int, array<string, mixed>>  $entries  employee id => entry attributes
 */
function pacRun(Tenant $tenant, string $start, string $end, string $status, array $entries): PayrollRun
{
    $run = PayrollRun::factory()->create([
        'tenant_id' => $tenant->id,
        'period_start' => $start,
        'period_end' => $end,
        'period_label' => Carbon::parse($start)->format('F Y'),
        'status' => 'completed',
    ]);

    foreach ($entries as $employeeId => $attributes) {
        PayrollEntry::factory()->create(['tenant_id' => $tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $employeeId, ...$attributes]);
    }

    // A run is released as it is in use: approved, then paid.
    foreach ($status === 'paid' ? ['approved', 'paid'] : ($status === 'completed' ? [] : [$status]) as $next) {
        $run->forceFill(['status' => $next])->save();
    }

    return $run;
}

function pacChecks(PayrollRun $run): array
{
    return app(RunRegisterService::class)->register($run);
}

function pacBank(Tenant $tenant, Employee $employee, string $number, string $bank = 'Commercial Bank of Ethiopia', bool $primary = true): EmployeeBankDetail
{
    return EmployeeBankDetail::create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'bank_name' => $bank,
        'account_number' => $number,
        'is_primary' => $primary,
    ]);
}

describe('pay changes', function () {
    it('lists net pay that moved past both thresholds since the last released run, with what moved', function () {
        $tenant = createTenant();
        $raised = pacEmployee($tenant);
        $steady = pacEmployee($tenant);
        $small = pacEmployee($tenant, ['tin' => '0012345679']);

        pacRun($tenant, '2026-09-01', '2026-09-30', 'paid', [
            $raised->id => ['basic_salary_cents' => 1_000_000, 'gross_cents' => 1_000_000, 'income_tax_cents' => 100_000, 'net_cents' => 900_000],
            $steady->id => ['net_cents' => 500_000],
            $small->id => ['basic_salary_cents' => 100_000, 'gross_cents' => 100_000, 'net_cents' => 100_000],
        ]);
        $run = pacRun($tenant, '2026-10-01', '2026-10-31', 'completed', [
            $raised->id => ['basic_salary_cents' => 1_500_000, 'gross_cents' => 1_500_000, 'income_tax_cents' => 250_000, 'net_cents' => 1_250_000],
            $steady->id => ['net_cents' => 510_000],
            // 30% more, but ETB 300 is under the ETB 500 minimum.
            $small->id => ['basic_salary_cents' => 130_000, 'gross_cents' => 130_000, 'net_cents' => 130_000],
        ]);

        $changes = pacChecks($run)['pay_changes'];

        expect($changes)->toMatchArray(['count' => 1, 'previous_period' => 'September 2026', 'first_payslip' => 0, 'percent' => 20, 'min_cents' => 50_000])
            ->and($changes['employees'][0])->toMatchArray([
                'employee_public_id' => $raised->public_id,
                'previous_net_cents' => 900_000,
                'net_cents' => 1_250_000,
                'change_cents' => 350_000,
                'change_percent' => 38.9,
            ])
            ->and($changes['employees'][0]['reasons'])->toBe([
                ['part' => 'basic_cents', 'change_cents' => 500_000],
                ['part' => 'income_tax_cents', 'change_cents' => 150_000],
            ]);
    });

    it('does not compare with a voided run, nor flag a first payslip', function () {
        $tenant = createTenant();
        $person = pacEmployee($tenant);
        $newcomer = pacEmployee($tenant);

        pacRun($tenant, '2026-08-01', '2026-08-31', 'approved', [$person->id => ['net_cents' => 500_000]]);
        pacRun($tenant, '2026-09-01', '2026-09-30', 'voided', [$person->id => ['net_cents' => 100_000]]);
        $run = pacRun($tenant, '2026-10-01', '2026-10-31', 'completed', [
            $person->id => ['net_cents' => 500_000],
            $newcomer->id => ['net_cents' => 400_000],
        ]);

        $changes = pacChecks($run)['pay_changes'];

        expect($changes)->toMatchArray(['count' => 0, 'previous_period' => 'August 2026', 'first_payslip' => 1]);
    });

    it('lists nothing for an organisation\'s first run', function () {
        $tenant = createTenant();
        $person = pacEmployee($tenant);
        $run = pacRun($tenant, '2026-10-01', '2026-10-31', 'completed', [$person->id => ['net_cents' => 500_000]]);

        expect(pacChecks($run)['pay_changes'])->toMatchArray(['count' => 0, 'previous_period' => null, 'first_payslip' => 0]);
    });

    it('follows the organisation\'s thresholds, and ignores a stored value out of range', function () {
        $tenant = createTenant();
        $tenant->update(['settings' => [...($tenant->settings ?? []), 'pay_change_alert_percent' => 5, 'pay_change_alert_min_cents' => 10_000]]);
        $person = pacEmployee($tenant);
        pacRun($tenant, '2026-09-01', '2026-09-30', 'approved', [$person->id => ['net_cents' => 500_000]]);
        $run = pacRun($tenant, '2026-10-01', '2026-10-31', 'completed', [$person->id => ['net_cents' => 530_000]]);

        expect(pacChecks($run)['pay_changes']['count'])->toBe(1);

        $tenant->update(['settings' => [...$tenant->settings, 'pay_change_alert_percent' => 900]]);
        expect(PayChangeThresholds::forTenant($tenant->fresh())->percent)->toBe(PayChangeThresholds::DEFAULT_PERCENT);
    });

    it('names basic before lateness, and lateness on its own', function () {
        $reasons = PreApprovalChecks::reasons(
            ['basic_cents' => 1_000_000, 'lateness_cents' => 0, 'loan_cents' => 0],
            ['basic_cents' => 950_000, 'lateness_cents' => 50_000, 'loan_cents' => 20_000],
        );

        expect($reasons)->toBe([
            ['part' => 'lateness_cents', 'change_cents' => 50_000],
            ['part' => 'loan_cents', 'change_cents' => 20_000],
        ]);
    });
});

describe('shared bank accounts', function () {
    it('groups people of the run paid into one account, however the number is written', function () {
        $tenant = createTenant();
        [$a, $b, $c] = [pacEmployee($tenant), pacEmployee($tenant), pacEmployee($tenant)];
        pacBank($tenant, $a, '1000 1234 5678');
        pacBank($tenant, $b, '1000-1234-5678', 'CBE');
        pacBank($tenant, $c, '1000 9999 0000');
        $run = pacRun($tenant, '2026-10-01', '2026-10-31', 'completed', [$a->id => [], $b->id => [], $c->id => []]);

        $shared = pacChecks($run)['shared_bank_accounts'];

        expect($shared['count'])->toBe(1)
            ->and($shared['pending_hashes'])->toBe(0)
            ->and(array_column($shared['groups'][0]['employees'], 'employee_public_id'))->toBe([$a->public_id, $b->public_id])
            ->and($shared['groups'][0]['banks'])->toBe(['Commercial Bank of Ethiopia', 'CBE']);
    });

    it('compares the account people are paid into, the primary one', function () {
        $tenant = createTenant();
        [$a, $b] = [pacEmployee($tenant), pacEmployee($tenant)];
        pacBank($tenant, $a, '1000 1234 5678');
        pacBank($tenant, $b, '1000 1234 5678', primary: false);
        pacBank($tenant, $b, '2000 0000 0001');
        $run = pacRun($tenant, '2026-10-01', '2026-10-31', 'completed', [$a->id => [], $b->id => []]);

        expect(pacChecks($run)['shared_bank_accounts']['count'])->toBe(0);
    });

    it('never matches an account of another organisation', function () {
        $tenant = createTenant();
        $other = createTenant();
        $mine = pacEmployee($tenant);
        $theirs = pacEmployee($other);
        pacBank($tenant, $mine, '1000 1234 5678');
        pacBank($other, $theirs, '1000 1234 5678');
        $run = pacRun($tenant, '2026-10-01', '2026-10-31', 'completed', [$mine->id => []]);

        expect(pacChecks($run)['shared_bank_accounts']['count'])->toBe(0);
    });

    it('keeps the hash out of every answer', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
        [$a, $b] = [pacEmployee($tenant), pacEmployee($tenant)];
        $detail = pacBank($tenant, $a, '1000 1234 5678');
        pacBank($tenant, $b, '1000 1234 5678');
        $run = pacRun($tenant, '2026-10-01', '2026-10-31', 'completed', [$a->id => [], $b->id => []]);
        $hash = (string) $detail->fresh()?->account_number_hash;

        expect($hash)->toHaveLength(64)
            ->and($detail->fresh()?->toArray())->not->toHaveKey('account_number_hash');

        test()->getJson(pacUrl($tenant, "payroll/runs/{$run->public_id}/register"))
            ->assertOk()
            ->assertJsonPath('shared_bank_accounts.count', 1)
            ->assertDontSee($hash)
            ->assertDontSee('1000 1234 5678');
    });

    it('says how many accounts are still waiting for a hash, and the backfill fills them a slice at a time', function () {
        $tenant = createTenant();
        [$a, $b] = [pacEmployee($tenant), pacEmployee($tenant)];
        pacBank($tenant, $a, '1000 1234 5678');
        pacBank($tenant, $b, '1000 1234 5678');
        // As saved before the column existed.
        DB::table('employee_bank_details')->where('tenant_id', $tenant->id)->update(['account_number_hash' => null]);
        // More than one slice of older rows, of people outside the run.
        $filler = pacEmployee($tenant);
        DB::table('employee_bank_details')->insert(array_map(fn (int $i): array => [
            'public_id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'employee_id' => $filler->id,
            'bank_name' => 'Awash',
            'account_number' => Crypt::encryptString('3000'.$i),
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], range(1, BackfillBankAccountHashesJob::CHUNK)));
        $run = pacRun($tenant, '2026-10-01', '2026-10-31', 'completed', [$a->id => [], $b->id => []]);

        expect(pacChecks($run)['shared_bank_accounts'])->toMatchArray(['count' => 0, 'pending_hashes' => 2]);

        // A deadline already passed still does one slice, and says what is left.
        $left = BackfillBankAccountHashesJob::fill($tenant->id, microtime(true) - 1);
        expect($left)->toBe(2);

        expect(BackfillBankAccountHashesJob::fill($tenant->id, microtime(true) + 60))->toBe(0)
            ->and(pacChecks($run)['shared_bank_accounts'])->toMatchArray(['count' => 1, 'pending_hashes' => 0]);
    });

    it('shows no shared account to an operator in a support session', function () {
        $platform = createTenant();
        $operator = createUser(['role' => UserRole::SUPER_ADMIN], $platform);
        $tenant = createTenant();
        $admin = createUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        [$a, $b] = [pacEmployee($tenant), pacEmployee($tenant)];
        pacBank($tenant, $a, '1000 1234 5678');
        pacBank($tenant, $b, '1000 1234 5678');
        $run = pacRun($tenant, '2026-10-01', '2026-10-31', 'completed', [$a->id => [], $b->id => []]);
        $token = $admin->createToken(ImpersonationToken::nameFor($operator->id), ['*', ImpersonationToken::ABILITY], now()->addMinutes(30))->plainTextToken;

        app('auth')->forgetGuards();
        test()->withToken($token)->getJson(pacUrl($tenant, "payroll/runs/{$run->public_id}/register"))
            ->assertOk()
            ->assertJsonPath('shared_bank_accounts.count', 0)
            ->assertJsonPath('shared_bank_accounts.support_session', true)
            ->assertJsonPath('shared_bank_accounts.groups', []);
    });

    it('keeps one backfill queued per organisation', function () {
        Queue::fake();
        $tenant = createTenant();

        BackfillBankAccountHashesJob::dispatch($tenant->id);
        BackfillBankAccountHashesJob::dispatch($tenant->id);
        BackfillBankAccountHashesJob::dispatch(createTenant()->id);

        Queue::assertPushed(BackfillBankAccountHashesJob::class, 2);
    });

    it('does not queue itself again once every hash is filled', function () {
        Queue::fake();
        $tenant = createTenant();
        $person = pacEmployee($tenant);
        pacBank($tenant, $person, '1000 1234 5678');
        DB::table('employee_bank_details')->where('tenant_id', $tenant->id)->update(['account_number_hash' => null]);

        (new BackfillBankAccountHashesJob($tenant->id))->handle();

        Queue::assertNothingPushed();
        expect(BackfillBankAccountHashesJob::missing($tenant->id)->count())->toBe(0);
    });
});

describe('missing TIN and no attendance', function () {
    it('lists people paid with no TIN, and not those paid nothing', function () {
        $tenant = createTenant();
        $without = pacEmployee($tenant, ['tin' => null]);
        $unpaid = pacEmployee($tenant, ['tin' => null]);
        $with = pacEmployee($tenant);
        $run = pacRun($tenant, '2026-10-01', '2026-10-31', 'completed', [
            $without->id => [],
            $unpaid->id => ['net_cents' => 0],
            $with->id => [],
        ]);

        $missing = pacChecks($run)['missing_tin'];

        expect($missing['count'])->toBe(1)
            ->and($missing['employees'][0]['employee_public_id'])->toBe($without->public_id);
    });

    it('lists people paid who were expected at work and have no attendance at all', function () {
        $tenant = createTenant();
        $absent = pacEmployee($tenant);
        $partly = pacEmployee($tenant);
        AttendanceRecord::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $partly->id, 'date' => '2026-10-02']);
        $log = fn (array $days): array => ['steps' => [['step' => 'absences', 'unexplained_absence_days' => $days]]];
        $run = pacRun($tenant, '2026-10-01', '2026-10-31', 'completed', [
            $absent->id => ['calculation_log' => $log(['2026-10-01', '2026-10-02'])],
            $partly->id => ['calculation_log' => $log(['2026-10-01'])],
        ]);

        $none = pacChecks($run)['no_attendance'];

        expect($none['count'])->toBe(1)
            ->and($none['employees'][0])->toMatchArray(['employee_public_id' => $absent->public_id, 'days' => 2]);
    });

    it('says nothing on clean data', function () {
        $tenant = createTenant();
        [$a, $b] = [pacEmployee($tenant), pacEmployee($tenant)];
        pacBank($tenant, $a, '1000 1234 5678');
        pacBank($tenant, $b, '1000 1234 5679');
        pacRun($tenant, '2026-09-01', '2026-09-30', 'approved', [$a->id => [], $b->id => []]);
        $run = pacRun($tenant, '2026-10-01', '2026-10-31', 'completed', [$a->id => [], $b->id => []]);

        $register = pacChecks($run);

        expect($register['pay_changes']['count'])->toBe(0)
            ->and($register['shared_bank_accounts']['count'])->toBe(0)
            ->and($register['missing_tin']['count'])->toBe(0)
            ->and($register['no_attendance']['count'])->toBe(0);
    });
});

it('reads none of it for the approval, the CSV and the schedules', function () {
    $tenant = createTenant();
    $without = pacEmployee($tenant, ['tin' => null]);
    $run = pacRun($tenant, '2026-10-01', '2026-10-31', 'completed', [$without->id => []]);

    $register = app(RunRegisterService::class)->register($run, checks: false);

    expect($register['missing_tin']['count'])->toBe(0)
        ->and(pacChecks($run)['missing_tin']['count'])->toBe(1);
});

it('lets a run with every list full be approved all the same', function () {
    test()->travelTo(Carbon::parse('2026-07-05 10:00', 'Africa/Addis_Ababa'));
    $tenant = createTenant();
    $finance = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $absent = pacEmployee($tenant, ['tin' => null, 'salary_cents' => 1_000_000]);
    $present = pacEmployee($tenant, ['salary_cents' => 1_000_000]);
    foreach (CarbonPeriod::create('2026-06-01', '2026-06-30') as $day) {
        if (! $day->isWeekend()) {
            AttendanceRecord::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $present->id, 'date' => $day->format('Y-m-d')]);
        }
    }
    pacBank($tenant, $absent, '1000 1234 5678');
    pacBank($tenant, $present, '1000 1234 5678');
    pacRun($tenant, '2026-05-01', '2026-05-31', 'approved', [$absent->id => ['net_cents' => 100_000], $present->id => ['net_cents' => 100_000]]);

    $run = app(PayrollEngine::class)->process($tenant->id, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), $finance->id)->run;

    test()->getJson(pacUrl($tenant, "payroll/runs/{$run->public_id}/register"))
        ->assertOk()
        ->assertJsonPath('pay_changes.count', 2)
        ->assertJsonPath('shared_bank_accounts.count', 1)
        ->assertJsonPath('missing_tin.count', 1)
        ->assertJsonPath('no_attendance.count', 1);

    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    test()->putJson(pacUrl($tenant, "payroll/runs/{$run->public_id}/approve"))->assertOk();

    expect($run->fresh()?->status)->toBe('approved');
});

it('saves the thresholds and refuses one out of range', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->putJson(pacUrl($tenant, 'settings'), ['settings' => ['pay_change_alert_percent' => 15, 'pay_change_alert_min_cents' => 20_000]])
        ->assertOk();
    test()->getJson(pacUrl($tenant, 'settings'))
        ->assertOk()
        ->assertJsonPath('payroll.pay_change_alert_percent', 15)
        ->assertJsonPath('payroll.pay_change_alert_min_cents', 20_000);

    test()->putJson(pacUrl($tenant, 'settings'), ['settings' => ['pay_change_alert_percent' => 0]])
        ->assertStatus(422);
    test()->putJson(pacUrl($tenant, 'settings'), ['settings' => ['pay_change_alert_min_cents' => -1]])
        ->assertStatus(422);
});
