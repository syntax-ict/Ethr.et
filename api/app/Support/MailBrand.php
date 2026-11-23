<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;

/**
 * Who the email now being rendered is from: the organisation's name and
 * contact lines, or ETHR's own when there is no organisation.
 *
 * Bound as `scoped`, so a long-lived worker never carries one job's
 * organisation into the next, and filled by `BrandMail` just before a
 * notification's mail is built. The logo is left out on purpose: it is a data
 * URI, and mail clients such as Gmail do not draw those.
 */
final class MailBrand
{
    public const PLATFORM_NAME = 'ETHR';

    private string $name = self::PLATFORM_NAME;

    /** @var list<string> */
    private array $lines = [];

    private bool $platform = true;

    public function use(?Tenant $tenant, string $locale): void
    {
        $letterhead = Letterhead::for($tenant, $locale, withTin: false, withLogo: false);

        $this->platform = $tenant === null;
        $this->name = trim($letterhead['name']) !== '' ? $letterhead['name'] : self::PLATFORM_NAME;
        $this->lines = $letterhead['lines'];
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return list<string> */
    public function lines(): array
    {
        return $this->lines;
    }

    public function isPlatform(): bool
    {
        return $this->platform;
    }
}
