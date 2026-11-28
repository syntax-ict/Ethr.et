<?php

declare(strict_types=1);

use App\Services\Payroll\BankExportService;
use App\Services\Payroll\StatutorySchedules;
use Carbon\Carbon;
use Tests\Accuracy\Payroll\FixtureError;
use Tests\Accuracy\Restated\RestatedEntries;
use Tests\Accuracy\Restated\RunOutputs;

/*
 * The bank file and the statutory schedules of an approved run, read as the CSV
 * a bank or an agency receives, against the stored entries and the register.
 *
 * Already asserted by the payroll cases (PayrollFields run fields), so NOT
 * repeated here: bank.rows and bank.total of the CSV and of the run page,
 * schedule.income_tax_total, schedule.pension_rows and
 * schedule.pension_employee_total of StatutorySchedules::build().
 *
 * Added here: every bank row's amount, name and account against the register and
 * the stored bank details, the row order, the run page's rows against the CSV's;
 * every schedule row and the CSV totals line against the entries, and the
 * pension employer share, which no payroll field states.
 *
 * Nothing here asserts an outcome for a person deleted after approval
 * (PYR-BANK-04 to 06 are Undecided): the case used deletes nobody.
 */

it('pays in the bank file each persons net into the account on record', function (): void {
    $id = 'GR-BANK-001';
    $compared = 0;
    $misses = [];
    $same = static function (string $field, mixed $engine, mixed $shown) use (&$compared, &$misses): void {
        $compared++;
        if ($engine !== $shown) {
            $misses[] = "bank file, {$field}: the engine has ".json_encode($engine).' and the file shows '.json_encode($shown);
        }
    };

    try {
        $made = RestatedEntries::run('GP-RUN-001');
        $expectedAccounts = RunOutputs::addBankDetails($made['built']['employees'], $made['built']['tenant']->id);
        $run = $made['run'];

        $rows = RunOutputs::bankRows($run);
        $register = RunOutputs::register($run);
        $entries = RunOutputs::entries($run);

        $same('number of rows against the register', count($register), count($rows));
        $same('row order against the register', array_column($register, 'employee_code'), array_column($rows, 'code'));
        $same('row order against the stored entries', array_column($entries, 'code'), array_column($rows, 'code'));
        $same('the same file on a second run', app(BankExportService::class)->generateCsv($run), app(BankExportService::class)->generateCsv($run));

        foreach ($rows as $i => $row) {
            $line = $register[$i];
            $code = $row['code'];
            $same("{$code} amount against the register net", $line['net_cents'], $row['cents']);
            $same("{$code} amount against the entry net", $entries[$i]['net'], $row['cents']);
            $same("{$code} name against the register", $line['employee_name'], $row['name']);
            $same("{$code} name against the entry", $entries[$i]['name'], $row['name']);
            $same("{$code} bank name against the stored details", $expectedAccounts[$code]['bank'], $row['bank']);
            $same("{$code} account number against the stored details", $expectedAccounts[$code]['account'], $row['account']);
            $same("{$code} currency", 'ETB', $row['currency']);
        }

        // The run page's file, row by row against the CSV.
        $page = test()->getJson("/api/v1/payroll/runs/{$run->public_id}/export/bank");
        $page->assertOk();
        $pageRows = $page->json('rows');
        $same('run page, number of rows against the CSV', count($rows), count($pageRows));
        foreach ($rows as $i => $row) {
            $shown = $pageRows[$i] ?? [];
            $same("run page {$row['code']} code", $row['code'], $shown['employee_code'] ?? null);
            $same("run page {$row['code']} amount", $row['cents'], $shown['net_amount_cents'] ?? null);
            $same("run page {$row['code']} name", $row['name'], $shown['employee_name'] ?? null);
            $same("run page {$row['code']} bank name", $row['bank'], $shown['bank_name'] ?? null);
            $same("run page {$row['code']} account number", $row['account'], $shown['account_number'] ?? null);
        }
    } catch (FixtureError $e) {
        test()->fail("[{$id}] ".$e->getMessage());
    } finally {
        Carbon::setTestNow();
    }

    fwrite(STDERR, "RESTATED {$id}: {$compared} figures compared
");

    RestatedEntries::settle($id, $misses, $compared);
});

it('files in each schedule the figures of the entries', function (): void {
    $id = 'GR-SCHEDULE-001';
    $compared = 0;
    $misses = [];
    $same = static function (string $field, mixed $engine, mixed $shown) use (&$compared, &$misses): void {
        $compared++;
        if ($engine !== $shown) {
            $misses[] = "schedule, {$field}: the entries have ".json_encode($engine).' and the schedule shows '.json_encode($shown);
        }
    };

    try {
        $made = RestatedEntries::run('GP-RUN-001');
        $run = $made['run'];
        $entries = RunOutputs::entries($run);
        $schedules = app(StatutorySchedules::class);

        // Income tax: one row per entry.
        $tax = $schedules->build($run, StatutorySchedules::INCOME_TAX);
        $taxCsv = RunOutputs::scheduleCsv($run, StatutorySchedules::INCOME_TAX);
        $same('income tax rows against the entries', count($entries), count($tax['rows']));
        $same('income tax CSV rows against the entries', count($entries), count($taxCsv['rows']));
        foreach ($entries as $i => $entry) {
            $same("income tax {$entry['code']} row", $entry['tax'], $tax['rows'][$i]['income_tax_cents'] ?? null);
            $same("income tax {$entry['code']} CSV row", $entry['tax'], $taxCsv['rows'][$entry['code']]['income_tax'] ?? null);
        }
        $same('income tax CSV totals line', array_sum(array_column($entries, 'tax')), $taxCsv['total']['income_tax'] ?? null);

        // Pension: one row per entry that is not exempt, with both shares.
        $on = array_values(array_filter($entries, static fn (array $e): bool => $e['exempt'] === null ? ($e['employee_pension'] > 0 || $e['employer_pension'] > 0) : ! $e['exempt']));
        $pension = $schedules->build($run, StatutorySchedules::PENSION);
        $pensionCsv = RunOutputs::scheduleCsv($run, StatutorySchedules::PENSION);
        $same('pension rows, who is on the schedule', array_column($on, 'code'), array_column($pension['rows'], 'employee_code'));
        $same('pension CSV rows, who is on the schedule', array_column($on, 'code'), array_keys($pensionCsv['rows']));
        foreach ($on as $i => $entry) {
            $same("pension {$entry['code']} employee share", $entry['employee_pension'], $pension['rows'][$i]['employee_pension_cents'] ?? null);
            $same("pension {$entry['code']} employer share", $entry['employer_pension'], $pension['rows'][$i]['employer_pension_cents'] ?? null);
            $same("pension {$entry['code']} CSV employee share", $entry['employee_pension'], $pensionCsv['rows'][$entry['code']]['employee_pension'] ?? null);
            $same("pension {$entry['code']} CSV employer share", $entry['employer_pension'], $pensionCsv['rows'][$entry['code']]['employer_pension'] ?? null);
        }
        $employeeShares = array_sum(array_column($on, 'employee_pension'));
        $employerShares = array_sum(array_column($on, 'employer_pension'));
        $same('pension total, employee shares', $employeeShares, $pension['totals']['employee_pension_cents'] ?? null);
        $same('pension total, employer shares', $employerShares, $pension['totals']['employer_pension_cents'] ?? null);
        $same('pension CSV totals line, employee shares', $employeeShares, $pensionCsv['total']['employee_pension'] ?? null);
        $same('pension CSV totals line, employer shares', $employerShares, $pensionCsv['total']['employer_pension'] ?? null);
        if (isset($pensionCsv['total']['total_pension'])) {
            $same('pension CSV totals line, both shares', $employeeShares + $employerShares, $pensionCsv['total']['total_pension']);
        }
    } catch (FixtureError $e) {
        test()->fail("[{$id}] ".$e->getMessage());
    } finally {
        Carbon::setTestNow();
    }

    fwrite(STDERR, "RESTATED {$id}: {$compared} figures compared
");

    RestatedEntries::settle($id, $misses, $compared);
});
