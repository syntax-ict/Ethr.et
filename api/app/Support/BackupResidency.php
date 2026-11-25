<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Where an off-host backup may be stored: in Ethiopia, in production.
 *
 * Proclamation No. 1321/2024 Art. 22(1) requires personal data collected in
 * Ethiopia to be stored on a server or data centre in Ethiopia. A backup holds
 * every organisation's people, so it is held to the same rule as the live
 * database. Encrypting the archive before it leaves does not change where it
 * is held.
 *
 * The rule is a blocker on a destination, never its removal: a destination
 * that is blocked takes no new copy and is not pruned, but an archive already
 * there can still be read back (`ethr:backup:unpack --destination=...`). A
 * disaster recovery must not depend on a rule that was added after the copy
 * was made.
 *
 * Nothing can tell from an endpoint where a bucket physically is, so for S3
 * the operator states it, in the console (`located_in_ethiopia`) or in `.env`
 * (BACKUP_OFF_HOST_IN_ETHIOPIA), and the statement is audited. Google Drive
 * stores outside Ethiopia by its own terms, so it is refused outright.
 *
 * Off production the rule is not applied: development and test machines copy
 * nothing of value, and the suites exercise every destination.
 */
final class BackupResidency
{
    /** The blocker code; the operations centre translates it. */
    public const BLOCKER = 'off_host_outside_ethiopia';

    public static function enforced(): bool
    {
        return app()->isProduction();
    }

    /** Whether a destination whose location is $inEthiopia must refuse copies. */
    public static function refuses(bool $inEthiopia): bool
    {
        return self::enforced() && ! $inEthiopia;
    }
}
