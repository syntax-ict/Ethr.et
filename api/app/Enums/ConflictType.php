<?php

declare(strict_types=1);

namespace App\Enums;

enum ConflictType: string
{
    case TIME_OVERLAP = 'time_overlap';
    case MISSING_CHECK_OUT = 'missing_check_out';
    case MISSING_CHECK_IN = 'missing_check_in';
    case DUPLICATE_SOURCE = 'duplicate_source';
    case MULTI_SOURCE_FAR = 'multi_source_far';
    case RETROACTIVE = 'retroactive';
}
