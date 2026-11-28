<?php

declare(strict_types=1);

/*
 * Disagreements between the engine and the independent oracle that a person
 * has looked at and recorded, so the generated run stays green while they are
 * triaged. '<seed>/<date>/<field>' => one line. A per-record field carries
 * '#<n>', its record's number on the day (an outcome, its punch's number); a
 * month-level field uses 'month' for the date. A listed one that no longer disagrees fails until it is removed.
 * Entries are added by a person after triage, never by the run.
 */

return [
];
