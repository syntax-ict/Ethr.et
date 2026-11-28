<?php

declare(strict_types=1);

/*
 * Recorded findings: a golden case the engine disagrees with.
 *
 * A hand value is never changed to make a case pass. A disagreement is listed
 * here, case id => one line saying what the engine answered, and the case is
 * then reported as incomplete with that text instead of passing silently or
 * going red. Each entry is triaged as an engine defect, a wrong hand value or
 * a fixture error; once it is resolved the entry is removed, and a case listed
 * here that the engine now agrees with fails until it is.
 */

return [
];
