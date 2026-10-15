<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Employee numbers from the organisation's own format.
 *
 * Industry templates have stored `settings.employee_number_format`
 * (`MIN-{SEQ:4}`, `HC-{SEQ:4}`, …) since onboarding v2, and nothing read it:
 * an employee added without a code simply had none, so they could not sign in
 * by code or check in at a kiosk until someone typed one. The format is one
 * `{SEQ:n}` token, n being the minimum width, between a fixed prefix and
 * suffix. The next number is one more than the highest already issued in that
 * shape, soft-deleted employees included, because their codes still hold the
 * `(tenant_id, employee_code)` unique index.
 *
 * Two people adding at once can be handed the same number. The index refuses
 * the second insert and assign() takes the next one, so a race costs a retry,
 * never a duplicate.
 */
final class EmployeeNumbering
{
    public const DEFAULT_FORMAT = 'EMP-{SEQ:4}';

    public const SETTING = 'employee_number_format';

    private const ATTEMPTS = 5;

    /** One `{SEQ:n}` with n from 1 to 8; prefix and suffix in the characters badge readers and kiosks accept. */
    public const SHAPE = '/^([A-Za-z0-9\/._-]{0,20})\{SEQ:([1-8])\}([A-Za-z0-9\/._-]{0,10})$/';

    public static function isValidFormat(mixed $format): bool
    {
        return is_string($format) && preg_match(self::SHAPE, $format) === 1;
    }

    public function format(Tenant $tenant): string
    {
        $format = data_get($tenant->settings, self::SETTING);

        return self::isValidFormat($format) ? $format : self::DEFAULT_FORMAT;
    }

    /** The code the next employee would get, if nobody takes it first. */
    public function next(Tenant $tenant, int $skip = 0): string
    {
        return $this->sequence($tenant, 1 + $skip)[$skip];
    }

    /**
     * The next $count codes, in order, for a batch such as an import.
     *
     * @return list<string>
     */
    public function sequence(Tenant $tenant, int $count): array
    {
        [$prefix, $width, $suffix] = $this->parts($this->format($tenant));

        $pattern = '/^'.preg_quote($prefix, '/').'(\d+)'.preg_quote($suffix, '/').'$/';
        $highest = 0;

        // LIKE only narrows the read; the pattern decides. The format's
        // characters cannot include `%`, and a `_` there is a wildcard that
        // lets a few extra codes through to the regex, which drops them.
        Employee::withTrashed()
            ->where('tenant_id', $tenant->id)
            ->where('employee_code', 'like', $prefix.'%'.$suffix)
            ->pluck('employee_code')
            ->each(function (?string $code) use ($pattern, &$highest): void {
                if ($code !== null && preg_match($pattern, $code, $m) === 1) {
                    $highest = max($highest, (int) $m[1]);
                }
            });

        $codes = [];
        for ($i = 1; $i <= $count; $i++) {
            $codes[] = $prefix.str_pad((string) ($highest + $i), $width, '0', STR_PAD_LEFT).$suffix;
        }

        return $codes;
    }

    /**
     * Create the employee, with the next code when the attributes bring none.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(Tenant $tenant, array $attributes): Employee
    {
        $code = $attributes['employee_code'] ?? null;
        if (is_string($code) && $code !== '') {
            return Employee::create($attributes);
        }

        return $this->assign(
            $tenant,
            fn (string $next): Employee => Employee::create([...$attributes, 'employee_code' => $next]),
        );
    }

    /**
     * Run $create with the next free code, retrying on a collision.
     *
     * @template T
     *
     * @param  callable(string): T  $create
     * @return T
     */
    public function assign(Tenant $tenant, callable $create): mixed
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $create($this->next($tenant, $attempt));
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt + 1 >= self::ATTEMPTS || ! str_contains($e->getMessage(), 'employee_code')) {
                    throw $e;
                }
            }
        }
    }

    /** @return array{0: string, 1: int, 2: string} */
    private function parts(string $format): array
    {
        preg_match(self::SHAPE, $format, $m);

        return [$m[1], (int) $m[2], $m[3]];
    }
}
