<?php

declare(strict_types=1);

namespace App\Services\Identity;

/**
 * The signals available about a person from an external source (a device event,
 * an import row, a directory record), fed to IdentityResolver.
 *
 * The external-identity coordinates (source/identifier) are optional: when
 * present the resolver first checks for an existing exact mapping, and can
 * persist a new one via IdentityResolver::link().
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
    ) {}

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
}
