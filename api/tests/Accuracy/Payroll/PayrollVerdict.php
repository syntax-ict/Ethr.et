<?php

declare(strict_types=1);

namespace Tests\Accuracy\Payroll;

use Tests\Accuracy\Oracle\Payroll\PayslipOracle;

/**
 * What the independent oracle (tests/Accuracy/Oracle/Payroll) says about a
 * case the engine got wrong, in the wording of the attendance suite. Two
 * against one names the suspect; it does not prove it. Read only on a
 * disagreement, and only when the oracle exists.
 */
final class PayrollVerdict
{
    /** @param array<string, mixed> $case */
    public static function of(array $case): string
    {
        if (! class_exists(PayslipOracle::class)) {
            return 'THREE-WAY: not available, the payroll oracle (Tests\Accuracy\Oracle\Payroll\PayslipOracle) does not exist yet.';
        }

        $answer = PayslipOracle::compute(PayrollCases::question($case), PayrollCases::statutory());
        $oracle = PayrollComparer::compare($case, $answer);

        if ($oracle['mismatches'] === [] && $oracle['undecided'] === []) {
            return 'THREE-WAY: the oracle agrees with every hand value of this case, so the engine is outvoted two to one. Suspect the engine.';
        }

        $lines = array_map(static fn (string $m): string => '  oracle: '.$m, $oracle['mismatches']);
        foreach ($oracle['undecided'] as $u) {
            $lines[] = "  oracle: {$u['field']} depends on undecided rule {$u['rule']}";
        }

        if ($oracle['mismatches'] === []) {
            return "THREE-WAY: the oracle has no decided answer here; the field depends on an undecided rule. The register must be ruled on first.\n".implode("\n", $lines);
        }

        return "THREE-WAY: the oracle also disagrees with the hand value here. Suspect the hand value or the register first.\n".implode("\n", $lines);
    }
}
