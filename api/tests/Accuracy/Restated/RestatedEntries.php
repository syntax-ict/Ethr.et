<?php

declare(strict_types=1);

namespace Tests\Accuracy\Restated;

use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Services\Payroll\PayrollEngine;
use Carbon\Carbon;
use Tests\Accuracy\Payroll\FixtureError;
use Tests\Accuracy\Payroll\PayrollCases;
use Tests\Accuracy\Payroll\PayrollFixture;
use Throwable;

/**
 * Builds a payroll accuracy case, has the engine run it, and hands back the
 * stored entries with the engine's own figures read out of them. It reads; it
 * computes nothing.
 *
 * Static only: Pest loads every test file into one process.
 */
final class RestatedEntries
{
    /**
     * @return array<string, array<string, mixed>> the restated-figures cases by id (Payroll/cases/restated.json)
     */
    public static function cases(): array
    {
        $cases = [];
        foreach (json_decode((string) file_get_contents(dirname(__DIR__).'/Payroll/cases/restated.json'), true, 512, JSON_THROW_ON_ERROR) as $case) {
            $cases[$case['id']] = $case;
        }

        return $cases;
    }

    /** @return array<string, string> restated case id => what an output shows differently from the engine */
    public static function findings(): array
    {
        return require __DIR__.'/findings.php';
    }

    /**
     * The end of every restated check: no differences passes; a difference that
     * findings.php records and names is reported as incomplete; anything else
     * fails. A recorded finding must name the field of EVERY difference found, so
     * it cannot hide a second one, and a recorded finding that the output no
     * longer shows fails until it is removed.
     *
     * @param  list<string>  $misses  lines of the form "<output>, <field>: the engine has ... and the output shows ..."
     */
    public static function settle(string $key, array $misses, int $compared): void
    {
        expect($compared)->toBeGreaterThan(0, 'no figure was compared. '.implode(' | ', $misses));

        $finding = self::findings()[$key] ?? null;
        if ($finding !== null) {
            expect($misses)->not->toBeEmpty("{$key} is listed in findings.php but the output now agrees with the engine: remove it from the list");
            $unnamed = array_values(array_filter($misses, static function (string $miss) use ($finding): bool {
                $field = trim((string) strstr(substr((string) strstr($miss, ', '), 2), ':', true));

                return $field === '' || ! str_contains($finding, $field);
            }));
            expect($unnamed)->toBe([], "
{$key} has a finding recorded, but these differences are not the ones it names:
".implode('
', $unnamed));
            test()->markTestIncomplete("RECORDED FINDING {$key}: {$finding}
".implode('
', $misses));
        }

        expect($misses)->toBe([], '
'.implode('
', $misses));
    }

    /**
     * Builds the payroll case and has the engine run it: a run, approved when the case says so.
     *
     * @return array{case: array<string, mixed>, built: array<string, mixed>, run: PayrollRun}
     */
    public static function run(string $caseId): array
    {
        $case = PayrollCases::all()[$caseId] ?? null;
        if ($case === null) {
            throw FixtureError::fixture("the payroll case {$caseId} does not exist");
        }

        $built = PayrollFixture::build($case);
        $engine = app(PayrollEngine::class);
        try {
            $run = $engine->process($built['tenant']->id, Carbon::parse($case['period']['start']), Carbon::parse($case['period']['end']), $built['user']->id)->run;
            if (($case['run'] ?? 'preview') === 'approve') {
                $run = $engine->approve($run->fresh(), $built['user']->id);
            }
        } catch (Throwable $e) {
            throw FixtureError::fixture('the run was refused: '.$e->getMessage());
        }

        return ['case' => $case, 'built' => $built, 'run' => $run->fresh()];
    }

    /**
     * One stored entry as the figures an output restates: the columns, the itemised
     * lines, and the figures the engine logged for overtime and the reductions of basic.
     *
     * @return array<string, mixed>
     */
    public static function read(PayrollEntry $entry): array
    {
        $log = $entry->getAttribute('calculation_log');
        $steps = [];
        foreach ((is_array($log) && is_array($log['steps'] ?? null)) ? $log['steps'] : [] as $step) {
            if (is_array($step) && is_string($step['step'] ?? null)) {
                $steps[$step['step']] = $step;
            }
        }

        $afterReductions = isset($steps['lateness']['basic_after_lateness_cents'])
            ? (int) $steps['lateness']['basic_after_lateness_cents']
            : (int) $steps['sick_leave']['basic_after_sick_leave_cents'];

        return [
            'basic_salary_cents' => (int) $entry->basic_salary_cents,
            'basic_after_reductions' => $afterReductions,
            'overtime_cents' => (int) ($steps['overtime']['overtime_amount_cents'] ?? 0),
            'allowances' => array_values(array_map(static fn (array $a): array => ['name' => (string) ($a['name'] ?? ''), 'amount_cents' => (int) ($a['amount_cents'] ?? 0)], (array) $entry->allowances)),
            'gross_cents' => (int) $entry->gross_cents,
            'income_tax_cents' => (int) $entry->income_tax_cents,
            'employee_pension_cents' => (int) $entry->employee_pension_cents,
            'employer_pension_cents' => (int) $entry->employer_pension_cents,
            'other_deductions_cents' => (int) $entry->other_deductions_cents,
            'deductions' => array_values(array_map(static fn (array $d): array => ['type' => (string) ($d['type'] ?? ''), 'amount_cents' => (int) ($d['amount_cents'] ?? 0)], (array) $entry->getAttribute('deductions'))),
            'net_cents' => (int) $entry->net_cents,
        ];
    }
}
