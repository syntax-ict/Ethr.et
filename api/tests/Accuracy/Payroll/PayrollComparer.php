<?php

declare(strict_types=1);

namespace Tests\Accuracy\Payroll;

use Tests\Accuracy\Oracle\NoOpinion;
use Tests\Accuracy\Oracle\Undecided;

/**
 * A case's hand values against an answer, field by field: the engine's (read
 * by EngineReader) or the oracle's. The only place that reads `expect`; the
 * oracle never sees it. Same semantics as OracleComparer::check():
 *
 *  - a NoOpinion field goes to `no_opinion` and is neither agreement nor disagreement;
 *  - an Undecided field goes to `undecided`, with whether its baseline literal
 *    happens to equal the hand value;
 *  - anything else agrees when strictly equal (===), and is a mismatch otherwise.
 *
 * A mismatch line names the employee, the field, both figures and the rule ids
 * that govern the field.
 */
final class PayrollComparer
{
    /**
     * @param  array<string, mixed>  $case
     * @param  array{employees: array<string, array<string, mixed>>, run?: array<string, mixed>}  $answer
     * @param  array<string, string>  $unexposed  field pattern => reason, for the engine only: fields its entry and log do not carry
     * @return array{mismatches: list<string>, undecided: list<array{field: string, rule: string, literal_agrees: bool}>, no_opinion: list<string>, agreed: int, unanswered: list<string>, not_comparable: list<string>}
     */
    public static function compare(array $case, array $answer, string $who = 'oracle', array $unexposed = []): array
    {
        $result = ['mismatches' => [], 'undecided' => [], 'no_opinion' => [], 'agreed' => 0, 'unanswered' => [], 'not_comparable' => []];

        foreach ($case['expect'] as $key => $fields) {
            foreach ($fields as $field => $hand) {
                $label = $key === 'run' ? (string) $field : "{$key} {$field}";
                $given = $key === 'run' ? ($answer['run'] ?? []) : ($answer['employees'][$key] ?? []);
                if ($who === 'engine' && ! array_key_exists($field, $given)) {
                    // Nothing passes silently: a stated field the engine reader did not answer is
                    // either on the explicit list of fields the engine does not carry, or it fails.
                    self::classifyMissing($result, $label, (string) $field, $unexposed);

                    continue;
                }
                $mine = $given[$field] ?? new NoOpinion('not produced');

                self::check($result, $label, (string) $field, $hand, $mine, $who);
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, string>  $unexposed
     */
    private static function classifyMissing(array &$result, string $label, string $field, array $unexposed): void
    {
        foreach (array_keys($unexposed) as $pattern) {
            if (fnmatch($pattern, $field)) {
                $result['not_comparable'][] = $field;

                return;
            }
        }
        $result['unanswered'][] = $label;
    }

    /** @param array{mismatches: list<string>, undecided: list<array{field: string, rule: string, literal_agrees: bool}>, no_opinion: list<string>, agreed: int} $result */
    private static function check(array &$result, string $label, string $field, mixed $hand, mixed $mine, string $who): void
    {
        if ($mine instanceof NoOpinion) {
            $result['no_opinion'][] = $label;

            return;
        }

        if ($mine instanceof Undecided) {
            $result['undecided'][] = [
                'field' => $label,
                'rule' => $mine->label(),
                'literal_agrees' => $mine->literal !== null && ! $mine->literal instanceof NoOpinion && $mine->literal === $hand,
            ];

            return;
        }

        if ($hand === $mine) {
            $result['agreed']++;

            return;
        }

        $rules = PayrollFields::rulesOf($field);
        $result['mismatches'][] = sprintf(
            '%s expected %s, %s said %s%s',
            $label,
            json_encode($hand),
            $who,
            json_encode($mine),
            $rules === [] ? '' : ' ('.implode(', ', $rules).')',
        );
    }
}
