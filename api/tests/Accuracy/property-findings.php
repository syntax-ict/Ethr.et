<?php

declare(strict_types=1);

/*
 * Property violations that a person has looked at and recorded, so the
 * property run stays green while they are triaged. Entries are added by a
 * person after triage, never by the run.
 *
 * An exact entry: '<seed>/<property>/<date>' => one line, where <property> is
 * p1 to p5 and <date> is a work date or 'month'. A listed one that no longer
 * fails fails the run until it is removed.
 *
 * A pattern entry: 'pattern:<name>' => ['finding' => where it is recorded,
 * 'properties' => property names, 'classes' => the day's expected-day classes].
 * A violation of a listed property on a day of a listed class that the property
 * marks as a pattern (today only p5's late and early minutes on a day off) is a
 * known finding: it does not fail the run and the report lists it apart. A
 * pattern that matches nothing in a run of 10 months or more fails until it is
 * removed.
 */

return [];
