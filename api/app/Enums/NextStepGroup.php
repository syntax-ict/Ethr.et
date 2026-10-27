<?php

declare(strict_types=1);

namespace App\Enums;

/** What kind of help a next step is: the frontend groups and labels by it. */
enum NextStepGroup: string
{
    case Profile = 'profile';
    case Approvals = 'approvals';
    case Records = 'records';
    case Setup = 'setup';
    case Operations = 'operations';
}
