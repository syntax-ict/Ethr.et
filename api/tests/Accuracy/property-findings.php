<?php

declare(strict_types=1);

/*
 * Property violations that a person has looked at and recorded, so the
 * property run stays green while they are triaged.
 * '<seed>/<property>/<date>' => one line, where <property> is p1 to p5 and
 * <date> is a work date or 'month'. A listed one that no longer fails fails the
 * run until it is removed. Entries are added by a person after triage.
 */

return [
];
