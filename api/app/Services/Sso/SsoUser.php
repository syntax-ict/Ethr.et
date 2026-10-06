<?php

declare(strict_types=1);

namespace App\Services\Sso;

final class SsoUser
{
    public function __construct(
        public readonly string $nameId,
        public readonly string $email,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $phone = null,
        public readonly array $attributes = [],
        public readonly ?string $sessionIndex = null,
    ) {}

    public function fullName(): string
    {
        return trim(($this->firstName ?? '').' '.($this->lastName ?? '')) ?: $this->email;
    }
}
