<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The level of an education record, from the Ethiopian school ladder. `degree`
 * stays the free-text title ("BSc Accounting"); this is the level it sits at,
 * so a report can count graduates without parsing titles.
 */
enum EducationLevel: string
{
    case PRIMARY = 'primary';

    case SECONDARY = 'secondary';

    case PREPARATORY = 'preparatory';

    case CERTIFICATE = 'certificate';

    case DIPLOMA = 'diploma';

    case BACHELOR = 'bachelor';

    case MASTER = 'master';

    case DOCTORATE = 'doctorate';

    case OTHER = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $l) => $l->value, self::cases());
    }

    public static function fromInput(?string $value): ?self
    {
        return self::tryFrom(str_replace([' ', '-'], '_', mb_strtolower(trim((string) $value))));
    }
}
