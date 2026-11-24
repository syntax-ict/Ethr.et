<?php

declare(strict_types=1);

namespace App\Services\Report;

use Illuminate\Database\Eloquent\Builder;

/**
 * The unit filters a report puts on an employee query: department, branch and
 * cost centre, each by the unit's `public_id`. One implementation for the
 * report engine and the monthly timesheet, so a filter means the same in both.
 */
final class UnitFilter
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $filters
     * @return bool whether any unit filter was applied
     */
    public static function apply(Builder $query, array $filters): bool
    {
        $applied = false;
        foreach (['department' => 'department', 'branch' => 'branch', 'cost_center' => 'costCenter'] as $key => $relation) {
            $publicId = $filters[$key] ?? null;
            if (is_string($publicId) && $publicId !== '') {
                $query->whereHas($relation, fn (Builder $unit) => $unit->where('public_id', $publicId));
                $applied = true;
            }
        }

        return $applied;
    }
}
