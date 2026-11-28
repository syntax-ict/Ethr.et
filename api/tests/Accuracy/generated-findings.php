<?php

declare(strict_types=1);

/*
 * Disagreements between the engine and the independent oracle that a person
 * has looked at and recorded, so the generated run stays green while they are
 * triaged. Entries are added by a person after triage, never by the run.
 *
 * An exact entry: '<seed>/<date>/<field>' => one line. A per-record field
 * carries '#<n>', its record's number on the day (an outcome, its punch's
 * number); a month-level field uses 'month' for the date. A listed one that no
 * longer disagrees fails until it is removed.
 *
 * A pattern entry: 'pattern:<name>' => ['finding' => where it is recorded,
 * 'fields' => field names, 'classes' => the day's expected-day classes,
 * 'engine_nonzero' => true]. A disagreement whose field and day class are
 * listed, whose oracle value is 0 and whose engine value is not, is a known
 * finding: it does not fail the run and the report lists it apart from the
 * unexplained ones. A pattern that matches nothing in a run of 20 months or
 * more fails until it is removed.
 */

return [
    // Late and early minutes on a record of a day with no scheduled hours.
    // A day of approved leave is not one: AR-ABS-08 leaves its rating undecided.
    'pattern:day-off-late-early' => [
        'finding' => 'GC-REST-001, GC-REST-002 (BASELINE 25bt)',
        'fields' => ['late', 'early', 'late and early on a day off'],
        'classes' => ['rest_day', 'holiday', 'not_employed'],
        'engine_nonzero' => true,
    ],
];
