<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * What "this employee's record is incomplete" means, once.
 *
 * The Next Steps count of employees missing a TIN and the employees list that
 * `?filter[missing]=tin` opens must agree, so both build the condition here.
 * Two definitions of one number drift (audit N63).
 *
 * Encrypted columns are tested with `whereNull` only: the ciphertext of an
 * empty string is not empty, and the request layer already turns a blank
 * field into null.
 */
final class EmployeeGaps
{
    public const TIN = 'tin';

    public const BANK = 'bank';

    public const EMERGENCY_CONTACT = 'emergency_contact';

    public const PHONE = 'phone';

    /** @return list<string> */
    public static function keys(): array
    {
        return [self::TIN, self::BANK, self::EMERGENCY_CONTACT, self::PHONE];
    }

    /**
     * The gaps whose figures are pay data, which need the same permission as
     * filling them in.
     *
     * @return list<string>
     */
    public static function financial(): array
    {
        return [self::TIN, self::BANK];
    }

    /**
     * The "records to fix" filter of the employees list and its export: current
     * staff with the one gap `filter[missing]` names, or the query untouched
     * for a value that is not a gap. Pay data needs the permission that edits
     * pay data, so the list cannot be used to learn who has no TIN or bank
     * account.
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public static function restrict(Builder $query, Request $request): Builder
    {
        $gap = $request->input('filter.missing');

        if (! is_string($gap) || ! in_array($gap, self::keys(), true)) {
            return $query;
        }

        if (in_array($gap, self::financial(), true)) {
            Gate::authorize('employee.updateFinancial');
        }

        return self::apply($query->whereIn('status', EmployeeStatus::current()), $gap);
    }

    /**
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public static function apply(Builder $query, string $gap): Builder
    {
        return match ($gap) {
            self::TIN => $query->whereNull('tin'),
            self::BANK => $query->whereDoesntHave('bankDetails'),
            self::EMERGENCY_CONTACT => $query->whereDoesntHave('emergencyContacts'),
            self::PHONE => $query->where(fn (Builder $q) => $q->whereNull('phone')->orWhere('phone', '')),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
