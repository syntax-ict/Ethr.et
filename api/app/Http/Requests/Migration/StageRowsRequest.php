<?php

declare(strict_types=1);

namespace App\Http\Requests\Migration;

use App\Http\Requests\Migration\Concerns\ReadsPeopleGrid;
use App\Services\CurrentTenant;
use App\Support\EmployeeImportGrid;
use App\Support\EmployeeImportRow;
use App\Support\RelationMatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A list of people to stage, in one of two shapes.
 *
 * `grid` is the list as cells, the first line its column names or none: what
 * a person pasted or a file held. It is read as every list of people is, so
 * a column name, a date and a salary mean here what they mean in a file.
 *
 * `rows` is the list already keyed by field, for a caller that holds
 * structured values. It takes every column of the employee template, and
 * `department`, `branch` and `position` by code or name.
 *
 * `source` names a saved source of the organisation's: a grid is read with
 * its column names, and an `external_id` in either shape is kept against it.
 */
class StageRowsRequest extends FormRequest
{
    use ReadsPeopleGrid;

    /** The relation a keyed row names, and the column of the import it is. */
    private const RELATIONS = ['department' => 'department_code', 'branch' => 'branch_code', 'position' => 'position_code'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'source_type' => ['sometimes', 'string', 'in:csv,manual'],
            'source_ref' => ['nullable', 'string', 'max:100'],
            ...$this->gridRules('required_without:rows'),
            'skip_problems' => ['sometimes', 'boolean'],
            'rows' => ['required_without:grid', 'array', 'min:1', 'max:2000'],
            'rows.*' => ['array'],
            ...EmployeeImportRow::stagedRules('rows.*.', nameRequired: false),
            'rows.*.email' => ['nullable', 'email', 'max:255', 'distinct:ignore_case'],
            'rows.*.phone' => ['nullable', 'string', 'max:50'],
            'rows.*.employee_code' => ['nullable', 'string', 'max:30', 'distinct:ignore_case'],
            'rows.*.hire_date' => ['nullable', 'date_format:Y-m-d'],
            'rows.*.department' => ['nullable', 'string', 'max:255'],
            'rows.*.branch' => ['nullable', 'string', 'max:255'],
            'rows.*.position' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The people to stage, as a staged list keeps them
     * (EmployeeImportRow::staged), from whichever shape the request brought.
     *
     * @return list<array<string, mixed>>
     */
    public function staged(): array
    {
        $read = $this->people();
        if ($read !== null) {
            return $read->rows;
        }

        $staged = [];
        $source = $this->sourceName();
        foreach ((array) $this->validated('rows') as $row) {
            $row = is_array($row) ? $row : [];
            foreach (self::RELATIONS as $named => $column) {
                $row[$column] ??= $row[$named] ?? null;
            }
            $staged[] = EmployeeImportRow::staged($row, $source);
        }

        return $staged;
    }

    /**
     * Employee numbers are unique per organisation, so a list naming one
     * twice used to stage and then fail at commit with a server error. It is
     * refused here, before anything is staged.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rows.*.employee_code.distinct' => __('onboarding.migration.duplicate_code'),
            'rows.*.email.distinct' => __('onboarding.migration.duplicate_email'),
        ];
    }

    /**
     * A department, branch or position the organisation does not have is
     * refused here, row by row, rather than becoming a silent null at commit:
     * the person landed unassigned and nobody knew. The message is the
     * employee import's, which matches them the same way (RelationMatch).
     *
     * A grid is refused with every line that cannot be staged, by its number
     * as the reader counts it. The screen asks first (`/migration/read`) and
     * sends a list with none; this is what a caller that did not ask gets.
     * With `skip_problems` the lines that can be staged are, and the rest are
     * left out: the caller has been shown them and chose to go on.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $tenantId = app(CurrentTenant::class)->id();

            if ($tenantId === null || $validator->errors()->isNotEmpty()) {
                return;
            }

            $this->checkSource($validator);
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $read = $this->people();
            if ($read !== null && $read->needsName) {
                $validator->errors()->add('mapping', __('onboarding.migration.mapping_needs_name'));

                return;
            }
            if ($read !== null) {
                foreach ($this->boolean('skip_problems') ? [] : $read->problems as $problem) {
                    $validator->errors()->add('grid.'.($problem['line'] - 1), __('onboarding.migration.line', $problem));
                }
                if ($validator->errors()->isEmpty() && $read->rows === []) {
                    $validator->errors()->add('grid', __('onboarding.migration.nobody'));
                }
                if ($validator->errors()->isEmpty() && count($read->rows) > EmployeeImportGrid::MAX_PEOPLE) {
                    $validator->errors()->add('grid', __('onboarding.migration.too_many', [
                        'count' => count($read->rows),
                        'max' => EmployeeImportGrid::MAX_PEOPLE,
                    ]));
                }

                return;
            }

            $rows = $this->input('rows');
            if (! is_array($rows)) {
                return;
            }

            $matches = [
                'department' => RelationMatch::departments($tenantId),
                'branch' => null,
                'position' => null,
            ];

            foreach ($rows as $index => $row) {
                foreach (self::RELATIONS as $named => $column) {
                    $value = is_array($row) ? ($row[$named] ?? $row[$column] ?? null) : null;
                    if (! is_string($value) || trim($value) === '') {
                        continue;
                    }

                    $match = $matches[$named] ??= $named === 'branch'
                        ? RelationMatch::branches($tenantId)
                        : RelationMatch::positions($tenantId);
                    if ($match->id($value) !== null) {
                        continue;
                    }

                    $validator->errors()->add("rows.{$index}.{$named}", __(
                        $match->ambiguous($value) ? 'employee.import_relation_ambiguous' : 'employee.import_relation_not_found',
                        ['row' => (int) $index + 1, 'field' => __('employee.import_field_'.$column), 'value' => $value],
                    ));
                }
            }
        });
    }
}
