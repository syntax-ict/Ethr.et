<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * An organisation's records were erased, the tombstone and certificate were
 * written, and some of its files could not be removed.
 *
 * Nothing here was rolled back, and saying so would be wrong: the rows are
 * gone. The certificate carries `files_pending`, and OrganisationErasure
 * accepts the organisation again until the files are gone too.
 */
class ErasureIncomplete extends RuntimeException
{
    public function __construct(public readonly string $address, Throwable $previous)
    {
        parent::__construct(
            "The records of {$address} were erased and its certificate saved, but its files were not all removed: "
            .$previous->getMessage().' Erase it again to remove them.',
            0,
            $previous,
        );
    }
}
