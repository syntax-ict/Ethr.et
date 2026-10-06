<?php

declare(strict_types=1);

namespace App\Enums;

enum DisciplinaryDecision: string
{
    case GUILTY = 'guilty';
    case NOT_GUILTY = 'not_guilty';
    case INCONCLUSIVE = 'inconclusive';
}
