<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\Device;
use App\Support\EthiopianPhone;

/**
 * The signals available about a person from an external source (a device event,
 * an import row, a directory record), fed to IdentityResolver.
 *
 * The external-identity coordinates (source/identifier) are optional: when
 * present the resolver first checks for an existing exact mapping, and can
 * persist a new one via IdentityResolver::link().
 *
 * `codeIsGuess` says the employee code was not stated by anyone: a terminal
 * knows a person by a number, and that number is tried as an employee number
 * because many organisations enrol people under theirs. A stated code that
 * differs from the matched employee's is a contradiction; a guessed one that
 * differs is only a guess that missed.
 */
final readonly class IdentitySignals
{
    public function __construct(
        public ?string $employeeCode = null,
        public ?string $badgeNumber = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $name = null,
        public ?string $nationalId = null,
        // External-identity coordinates for lookup / linking.
        public ?string $sourceType = null,
        public ?string $sourceRef = null,
        public ?string $identifierType = null,
        public ?string $identifierValue = null,
        public bool $codeIsGuess = false,
    ) {}

    /**
     * A person as an employee record would describe them: the same keys the
     * record is created from, so a path cannot create with one set of details
     * and ask "is this someone we know?" with another. File import left the
     * badge out and the staged list left the national ID out, and each missed
     * the matches the other found.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function fromAttributes(array $attributes): self
    {
        $text = static function (string $key) use ($attributes): ?string {
            $value = $attributes[$key] ?? null;

            return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
        };

        return new self(
            employeeCode: $text('employee_code'),
            badgeNumber: $text('badge_number'),
            email: $text('email'),
            phone: $text('phone'),
            name: $text('name'),
            nationalId: $text('national_id'),
        );
    }

    /**
     * A person as a terminal knows them: its own user number, tried as an
     * employee number and as a badge, and the card when the terminal has one.
     *
     * The one definition for a punch that was pushed, a punch that was pulled,
     * a held punch being assigned and the enrolled-users list, which each
     * spelled these seven arguments out and had to agree for a number mapped by
     * one of them to resolve in the others.
     */
    public static function forDeviceUser(Device $device, string $userId, ?string $cardNumber = null, ?string $name = null): self
    {
        return new self(
            employeeCode: $userId,
            badgeNumber: $cardNumber ?? $userId,
            name: $name,
            sourceType: $device->adapter_type,
            sourceRef: 'device:'.$device->id,
            identifierType: 'device_user_id',
            identifierValue: $userId,
            codeIsGuess: true,
        );
    }

    public function hasExternalIdentity(): bool
    {
        return $this->sourceType !== null
            && $this->identifierType !== null
            && $this->identifierValue !== null
            && $this->identifierValue !== '';
    }

    /** Digits-only phone, for comparison across formatting differences. */
    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return $digits === '' ? null : $digits;
    }

    /**
     * Every digits-only shape one number may be stored as: `0911…`, `251911…`
     * and the bare subscriber number are one phone (EthiopianPhone), and rows
     * written before phones were canonicalised still hold whichever their
     * source used. A number that is not Ethiopian is only itself.
     *
     * @return list<string>
     */
    public static function phoneForms(?string $phone): array
    {
        if ($phone === null || trim($phone) === '') {
            return [];
        }

        $forms = [];
        foreach (EthiopianPhone::variants($phone) as $variant) {
            $digits = self::normalizePhone($variant);
            if ($digits !== null) {
                $forms[$digits] = true;
            }
        }

        return array_map(strval(...), array_keys($forms));
    }
}
