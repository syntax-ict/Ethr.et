<?php

declare(strict_types=1);

namespace App\Http\Requests\Report\Concerns;

use App\Services\Report\ReportEngine;
use App\Services\Report\ReportPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The rules a report's configuration answers to, in one place for the request
 * that runs a report and the one that saves it. A malformed `filters.from`
 * reached `Carbon::parse` and answered 500, and a `group_by` the source does
 * not have grouped every row under "Unknown" (report review 2026-11-15, A7).
 *
 * The columns are checked against the chosen source's own fields in the
 * validator's `after` hook: a rule cannot name them, as they depend on
 * `source`.
 *
 * @mixin FormRequest
 */
trait ValidatesReportConfig
{
    /**
     * @param  string  $prefix  `config.` where the configuration is nested.
     * @return array<string, mixed>
     */
    protected function configRules(string $prefix = ''): array
    {
        return [
            $prefix.'columns' => ['sometimes', 'array'],
            $prefix.'columns.*' => ['string', 'max:64'],
            $prefix.'filters' => ['sometimes', 'array'],
            $prefix.'filters.from' => ['nullable', 'date_format:Y-m-d'],
            $prefix.'filters.to' => ['nullable', 'date_format:Y-m-d'],
            $prefix.'filters.month' => ['nullable', 'date_format:Y-m'],
            $prefix.'filters.year' => ['nullable', 'integer'],
            $prefix.'filters.period' => ['nullable', Rule::in(ReportPeriod::KEYS)],
            $prefix.'filters.status' => ['nullable', 'string', 'max:50'],
            $prefix.'filters.department' => ['nullable', 'string', 'max:64'],
            $prefix.'filters.branch' => ['nullable', 'string', 'max:64'],
            $prefix.'filters.cost_center' => ['nullable', 'string', 'max:64'],
            $prefix.'group_by' => ['nullable', 'string', 'max:64'],
            $prefix.'sort_by' => ['nullable', 'string', 'max:64'],
        ];
    }

    /** Every column, group and sort field must be one the source has. */
    protected function checkConfigFields(Validator $validator, string $prefix, mixed $source, mixed $config): void
    {
        $definition = is_string($source) ? (app(ReportEngine::class)->sources()[$source] ?? null) : null;
        $fields = $definition['fields'] ?? null;
        if ($fields === null || ! is_array($config)) {
            return;
        }

        // A filter the source does not publish used to be dropped and the
        // caller handed the whole organisation. `month` is a timesheet's own,
        // kept for saved reports and API callers and left unpublished.
        $filters = is_array($config['filters'] ?? null) ? $config['filters'] : [];
        $allowed = [...$definition['filters'], ...($source === 'timesheet' ? ['month'] : [])];
        foreach (array_keys($filters) as $key) {
            if (! in_array($key, $allowed, true)) {
                $validator->errors()->add($prefix.'filters.'.$key, (string) __('validation.prohibited', ['attribute' => 'filter']));
            }
        }

        // A timesheet covers 31 days, so a longer period would report only
        // its oldest month.
        $period = $filters['period'] ?? null;
        if ($source === 'timesheet' && is_string($period) && in_array($period, ReportPeriod::KEYS, true) && ! in_array($period, ReportPeriod::SHORT_KEYS, true)) {
            $validator->errors()->add($prefix.'filters.period', (string) __('validation.in', ['attribute' => 'period']));
        }

        foreach ((array) ($config['columns'] ?? []) as $index => $column) {
            if (is_string($column) && ! in_array($column, $fields, true)) {
                $validator->errors()->add($prefix.'columns.'.$index, (string) __('validation.in', ['attribute' => 'column']));
            }
        }

        foreach (['group_by', 'sort_by'] as $key) {
            $field = $config[$key] ?? null;
            if (is_string($field) && $field !== '' && ! in_array($field, $fields, true)) {
                $validator->errors()->add($prefix.$key, (string) __('validation.in', ['attribute' => str_replace('_', ' ', $key)]));
            }
        }
    }
}
