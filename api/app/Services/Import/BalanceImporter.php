<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Support\Csv;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Opening balances brought over from the system an organisation used before:
 * approved leave balances and outstanding loans (blueprint assessment M4, M5).
 *
 * Both work the same way as the attendance and employee imports: a template,
 * a preview that checks every row and says what is wrong with it, and a
 * commit. The commit checks every row again rather than trusting what the
 * browser sends back, and changes nothing unless every row is valid.
 *
 * Leave: a row sets the year's entitled, used and carried days for one
 * employee and leave type, creating the balance or replacing it. Loans: a row
 * creates an active loan with its remaining balance; a row identical to a loan
 * already on file is skipped, so committing twice adds nothing.
 */
final class BalanceImporter
{
    public const LEAVE_HEADERS = ['employee_code', 'leave_type_code', 'year', 'entitled_days', 'used_days', 'carried_days'];

    public const LOAN_HEADERS = ['employee_code', 'principal_etb', 'remaining_etb', 'monthly_deduction_etb', 'start_date', 'reason'];

    private const LEAVE_REQUIRED = ['employee_code', 'leave_type_code', 'year', 'entitled_days'];

    private const LOAN_REQUIRED = ['employee_code', 'principal_etb', 'remaining_etb', 'monthly_deduction_etb', 'start_date'];

    public function template(string $kind): string
    {
        return implode(',', $kind === 'leave' ? self::LEAVE_HEADERS : self::LOAN_HEADERS)."\n";
    }

    /**
     * The file's rows keyed by header, each with `line`, `errors` and `valid`.
     *
     * @return array{rows: list<array<string, mixed>>, valid: int, invalid: int, errors: list<string>}
     */
    public function preview(string $kind, string $content): array
    {
        $lines = Csv::lines($content);

        if (count($lines) < 2) {
            return ['rows' => [], 'valid' => 0, 'invalid' => 0, 'errors' => [__('imports.balances.empty_file')]];
        }

        $header = array_map(fn (string $h) => strtolower(trim($h)), str_getcsv(array_shift($lines)));
        $missing = array_values(array_diff($kind === 'leave' ? self::LEAVE_REQUIRED : self::LOAN_REQUIRED, $header));

        if ($missing !== []) {
            return ['rows' => [], 'valid' => 0, 'invalid' => 0, 'errors' => [__('imports.balances.missing_columns', ['columns' => implode(', ', $missing)])]];
        }

        $rows = [];
        foreach ($lines as $i => $line) {
            $values = array_map(trim(...), str_getcsv($line) + array_fill(0, count($header), ''));
            $row = array_combine($header, array_slice($values, 0, count($header)));
            $row['line'] = $i + 2;
            $rows[] = $row;
        }

        return $this->checkAll($kind, $rows);
    }

    /**
     * Commit rows a preview returned. Every row is checked again; if any is
     * invalid nothing changes and the checked rows come back.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{committed: bool, created: int, updated: int, skipped: int, rows: list<array<string, mixed>>}
     */
    public function commit(string $kind, array $rows): array
    {
        $checked = $this->checkAll($kind, $rows);

        if ($checked['invalid'] > 0) {
            return ['committed' => false, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'rows' => $checked['rows']];
        }

        return DB::transaction(function () use ($kind, $checked) {
            $created = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($checked['rows'] as $row) {
                $outcome = $kind === 'leave' ? $this->writeLeave($row) : $this->writeLoan($row);
                match ($outcome) {
                    'created' => $created++,
                    'updated' => $updated++,
                    default => $skipped++,
                };
            }

            AuditLog::record($kind === 'leave' ? 'leave.balances_imported' : 'loan.balances_imported', null, [
                'rows' => count($checked['rows']),
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
            ]);

            return ['committed' => true, 'created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'rows' => []];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{rows: list<array<string, mixed>>, valid: int, invalid: int, errors: list<string>}
     */
    private function checkAll(string $kind, array $rows): array
    {
        $employees = Employee::query()
            ->whereIn('employee_code', array_values(array_unique(array_map(fn ($r) => (string) ($r['employee_code'] ?? ''), $rows))))
            ->get(['id', 'public_id', 'employee_code', 'name'])
            ->keyBy('employee_code');
        $leaveTypes = $kind === 'leave'
            ? LeaveType::query()->get(['id', 'code', 'name'])->keyBy(fn (LeaveType $t) => strtolower((string) $t->code))
            : collect();

        $valid = 0;
        $invalid = 0;
        $seen = [];
        $out = [];

        foreach ($rows as $row) {
            $errors = [];
            $code = (string) ($row['employee_code'] ?? '');
            $employee = $employees->get($code);

            if ($code === '') {
                $errors[] = __('imports.balances.employee_code_required');
            } elseif ($employee === null) {
                $errors[] = __('imports.balances.employee_not_found', ['code' => $code]);
            }

            $errors = [...$errors, ...($kind === 'leave' ? $this->leaveErrors($row, $leaveTypes) : $this->loanErrors($row))];

            if ($kind === 'leave' && $errors === []) {
                $key = $code.'|'.strtolower((string) $row['leave_type_code']).'|'.$row['year'];
                if (isset($seen[$key])) {
                    $errors[] = __('imports.balances.duplicate_row', ['line' => $seen[$key]]);
                }
                $seen[$key] = $row['line'] ?? 0;
            }

            $row['employee_name'] = $employee?->name;
            $row['errors'] = $errors;
            $row['valid'] = $errors === [];
            $row['valid'] ? $valid++ : $invalid++;
            $out[] = $row;
        }

        return ['rows' => $out, 'valid' => $valid, 'invalid' => $invalid, 'errors' => []];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<string, LeaveType>  $leaveTypes
     * @return list<string>
     */
    private function leaveErrors(array $row, Collection $leaveTypes): array
    {
        $errors = [];
        $type = strtolower((string) ($row['leave_type_code'] ?? ''));

        if ($type === '' || ! $leaveTypes->has($type)) {
            $errors[] = __('imports.balances.leave_type_not_found', ['code' => (string) ($row['leave_type_code'] ?? '')]);
        }

        $year = (string) ($row['year'] ?? '');
        if (! preg_match('/^\d{4}$/', $year) || (int) $year < 2000 || (int) $year > 2100) {
            $errors[] = __('imports.balances.invalid_year');
        }

        $days = [];
        foreach (['entitled_days', 'used_days', 'carried_days'] as $field) {
            $value = (string) ($row[$field] ?? '');
            if ($value === '' && $field !== 'entitled_days') {
                $days[$field] = 0.0;

                continue;
            }
            if (! preg_match('/^\d{1,3}(\.\d)?$/', $value)) {
                $errors[] = __('imports.balances.invalid_days', ['field' => $field]);

                continue;
            }
            $days[$field] = (float) $value;
        }

        if (count($days) === 3 && $days['used_days'] > $days['entitled_days'] + $days['carried_days']) {
            $errors[] = __('imports.balances.used_exceeds');
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function loanErrors(array $row): array
    {
        $errors = [];
        $cents = [];

        foreach (['principal_etb', 'remaining_etb', 'monthly_deduction_etb'] as $field) {
            $amount = self::cents((string) ($row[$field] ?? ''));
            if ($amount === null) {
                $errors[] = __('imports.balances.invalid_amount', ['field' => $field]);

                continue;
            }
            $cents[$field] = $amount;
        }

        if (count($cents) === 3) {
            if ($cents['principal_etb'] <= 0) {
                $errors[] = __('imports.balances.principal_positive');
            }
            if ($cents['remaining_etb'] > $cents['principal_etb']) {
                $errors[] = __('imports.balances.remaining_exceeds');
            }
            if ($cents['remaining_etb'] > 0 && $cents['monthly_deduction_etb'] <= 0) {
                $errors[] = __('imports.balances.deduction_positive');
            }
        }

        $date = (string) ($row['start_date'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! strtotime($date)) {
            $errors[] = __('imports.balances.invalid_date');
        }

        return $errors;
    }

    /** @param array<string, mixed> $row */
    private function writeLeave(array $row): string
    {
        $employee = Employee::query()->where('employee_code', $row['employee_code'])->firstOrFail();
        $type = LeaveType::query()->whereRaw('LOWER(code) = ?', [strtolower((string) $row['leave_type_code'])])->firstOrFail();

        $balance = LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->where('year', (int) $row['year'])
            ->first();

        $values = [
            'entitled_days' => (float) $row['entitled_days'],
            'used_days' => (float) (($row['used_days'] ?? '') !== '' ? $row['used_days'] : 0),
            'carried_days' => (float) (($row['carried_days'] ?? '') !== '' ? $row['carried_days'] : 0),
        ];

        if ($balance !== null) {
            $balance->update($values);

            return 'updated';
        }

        LeaveBalance::create([
            'tenant_id' => $employee->tenant_id,
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'year' => (int) $row['year'],
            'pending_days' => 0,
            ...$values,
        ]);

        return 'created';
    }

    /** @param array<string, mixed> $row */
    private function writeLoan(array $row): string
    {
        $employee = Employee::query()->where('employee_code', $row['employee_code'])->firstOrFail();
        $principal = (int) self::cents((string) $row['principal_etb']);
        $remaining = (int) self::cents((string) $row['remaining_etb']);
        $monthly = (int) self::cents((string) $row['monthly_deduction_etb']);
        $start = Carbon::parse((string) $row['start_date'])->toDateString();

        $exists = EmployeeLoan::query()
            ->where('employee_id', $employee->id)
            ->where('amount_cents', $principal)
            ->where('remaining_cents', $remaining)
            ->where('monthly_deduction_cents', $monthly)
            ->whereDate('start_date', $start)
            ->exists();
        if ($exists) {
            return 'skipped';
        }

        EmployeeLoan::create([
            'tenant_id' => $employee->tenant_id,
            'employee_id' => $employee->id,
            'amount_cents' => $principal,
            'remaining_cents' => $remaining,
            'monthly_deduction_cents' => $monthly,
            'start_date' => $start,
            'status' => $remaining > 0 ? 'active' : 'completed',
            'reason' => ($row['reason'] ?? '') !== '' ? mb_substr((string) $row['reason'], 0, 255) : __('imports.balances.loan_reason'),
        ]);

        return 'created';
    }

    /** ETB as written ("12500" or "12500.50") in cents, or null when it is not an amount. */
    private static function cents(string $etb): ?int
    {
        if (! preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $etb, $m)) {
            return null;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }
}
