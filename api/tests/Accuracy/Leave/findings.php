<?php

declare(strict_types=1);

/*
 * Recorded findings for the leave golden cases: a case the engine disagrees
 * with. A hand value is never changed to make a case pass. A disagreement is
 * listed here, case id => one line saying what the engine answered, and the
 * case is then reported as incomplete with that text. Each entry is triaged
 * as an engine defect, a wrong hand value or a fixture error; once resolved
 * the entry is removed, and a case listed here that the engine now agrees
 * with fails until it is.
 */

return [
    'GL-REQ-020' => 'LVR-DAYS-10 (b) says Labour Day makes Mon 2028-05-01 to Fri 2028-05-05 charge 4; the engine charged 3: the roll-forward also creates Ethiopian Patriots Day on Fri 2028-05-05 (a statutory holiday), which the register example does not mention. Probable wrong register example, not an engine defect.',
];
