<?php

declare(strict_types=1);

/*
 * Cases the fixture cannot build through public code. The case stays in its
 * file untouched; the golden test skips it with the reason here. case id =>
 * reason. A case that is not listed and cannot be built fails as NOT COVERABLE.
 */

return [
    'GP-SICK-003' => 'sick days full 4, half 3, unpaid 2 with 22 working days: the Art. 85 tiers count sick days in date order (full to day 22, half to day 66, unpaid from day 67), so unpaid days in the same period as only 3 half-pay days would need the 40 days between them to fall in the period too',
];
