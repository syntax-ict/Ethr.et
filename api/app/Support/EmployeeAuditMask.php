<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Keeps an employee's personal identifiers out of `audit_log.payload`.
 *
 * `employees.national_id` and `employees.tin` are `encrypted` casts, so reading
 * them off the model decrypts them. An audit record built from the model
 * (`$employee->only(...)`) therefore copied both values, old and new, into the
 * audit log in clear text, where they outlive the encryption, cannot be
 * removed (the log is append-only) and are read by people who hold no pay
 * permission. Measured 2026-11-10, `docs/audit/BASELINE.md` §25k.
 *
 * The rule, for each field in `FIELDS` that an edit named:
 *
 *   - no value stays `null`, before and after, so "set for the first time" and
 *     "cleared" are still readable;
 *   - a value that was there before is recorded as `[redacted]`;
 *   - a value that is there after is recorded as `[changed]` when it differs
 *     from the one before, and `[unchanged]` when the form resent the same one.
 *
 * Neither value, nor any part of one, is stored. Every other field passes
 * through untouched.
 *
 * Forward-only: rows written before this existed are left as they are.
 */
final class EmployeeAuditMask
{
    /** Employee attributes whose values never reach the audit log. */
    public const FIELDS = ['national_id', 'tin'];

    public const REDACTED = '[redacted]';

    public const CHANGED = '[changed]';

    public const UNCHANGED = '[unchanged]';

    /**
     * The `before` and `after` halves of an audit payload, with `FIELDS` masked.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    public static function apply(array $before, array $after): array
    {
        foreach (self::FIELDS as $field) {
            $had = array_key_exists($field, $before) && self::filled($before[$field]);
            $has = array_key_exists($field, $after) && self::filled($after[$field]);
            $same = $had && $has && (string) $before[$field] === (string) $after[$field];

            if (array_key_exists($field, $before)) {
                $before[$field] = $had ? self::REDACTED : null;
            }

            if (array_key_exists($field, $after)) {
                $after[$field] = match (true) {
                    ! $has => null,
                    $same => self::UNCHANGED,
                    default => self::CHANGED,
                };
            }
        }

        return ['before' => $before, 'after' => $after];
    }

    private static function filled(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }
}
