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
    // The engine defect of golden cases GC-REST-001 and GC-REST-002 (BASELINE 25bs):
    // late and early minutes on a record of a day that is not a working day.
    '20261160/2026-02-22/late#1' => 'S1 worked on a Sunday, in at 08:45:11: the engine says 15 minutes late on the record; the day has no scheduled hours (AR-MIN-06).',
];
