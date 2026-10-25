<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an employee is expected to punch (AttendancePolicy).
 */
enum AttendanceMode: string
{
    /** At their branch: its geofence applies as the organisation set it. */
    case OFFICE = 'office';

    /**
     * At any site they are assigned, or their branch. Outside all of them is
     * flagged for review, never refused: field work moves.
     */
    case FIELD = 'field';

    /** From wherever they work: location is recorded, no geofence applies. */
    case REMOTE = 'remote';
}
