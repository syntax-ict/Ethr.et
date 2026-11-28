<?php

declare(strict_types=1);

namespace Tests\Accuracy;

use Tests\Accuracy\Oracle\NoOpinion;
use Tests\Accuracy\Oracle\Undecided;

/**
 * A case's hand values against the oracle's answer, field by field. This is the
 * only place that reads `expect`; the oracle itself never sees it. A field the
 * oracle has no opinion on is skipped; a field that depends on an Undecided
 * rule is reported as such and never counted as agreement or disagreement.
 */
final class OracleComparer
{
    /**
     * @param  array<string, mixed>  $case
     * @param  array<string, mixed>  $answer  from CaseAdapter::run()
     * @return array{mismatches: list<string>, undecided: list<array{field: string, rule: string, literal_agrees: bool}>, no_opinion: list<string>, agreed: int}
     */
    public static function compare(array $case, array $answer): array
    {
        $result = ['mismatches' => [], 'undecided' => [], 'no_opinion' => [], 'agreed' => 0];
        $expect = $case['expect'];

        foreach (['live', 'conflicts'] as $field) {
            if (array_key_exists($field, $expect)) {
                self::check($result, $field, $expect[$field], $answer[$field]);
            }
        }

        foreach ($expect['outcomes'] ?? [] as $i => $hand) {
            $mine = array_key_exists($i, $answer['outcomes']) ? $answer['outcomes'][$i] : new NoOpinion('no such punch');
            self::check($result, 'outcome of punch '.($i + 1), $hand, $mine ?? new NoOpinion('the register gives no outcome for this punch'));
        }

        foreach ($expect['days'] ?? [] as $date => $fields) {
            $day = $answer['days'][$date] ?? null;
            foreach ($fields as $key => $hand) {
                $field = $date.' '.$key;
                if ($day === null) {
                    self::check($result, $field, $hand, new NoOpinion('date not produced'));

                    continue;
                }

                match ($key) {
                    // The timesheet code is presentation: only "absent" is asserted from it.
                    'code' => self::check($result, $date.' absent', $hand === 'A', $day['absent']),
                    'overtime' => self::check($result, $field, self::buckets($hand), self::bucketsOf($day['overtime'])),
                    'metadata' => self::metadata($result, $date, $hand, $day['metadata']),
                    'check_in', 'check_out' => self::check($result, $field, $hand, self::clock($hand, $day[$key])),
                    default => self::check($result, $field, $hand, array_key_exists($key, $day) ? $day[$key] : new NoOpinion('not produced')),
                };
            }
        }

        return $result;
    }

    /** The oracle always answers `H:i:s`; a hand value of `H:i` is compared at that precision. */
    private static function clock(mixed $hand, mixed $mine): mixed
    {
        if (is_string($hand) && strlen($hand) === 5 && is_string($mine) && str_ends_with($mine, ':00')) {
            return substr($mine, 0, 5);
        }

        return $mine;
    }

    /** @param array<string, mixed> $result */
    private static function check(array &$result, string $field, mixed $hand, mixed $mine): void
    {
        if ($mine instanceof NoOpinion) {
            $result['no_opinion'][] = $field;

            return;
        }

        if ($mine instanceof Undecided) {
            $result['undecided'][] = [
                'field' => $field,
                'rule' => $mine->label(),
                'literal_agrees' => $mine->literal !== null && ! $mine->literal instanceof NoOpinion && $mine->literal === $hand,
            ];

            return;
        }

        if ($hand === $mine) {
            $result['agreed']++;

            return;
        }

        $result['mismatches'][] = sprintf('%s expected %s, oracle said %s', $field, self::show($hand), self::show($mine));
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $hand
     */
    private static function metadata(array &$result, string $date, array $hand, mixed $mine): void
    {
        foreach ($hand as $key => $value) {
            if ($mine instanceof Undecided || $mine instanceof NoOpinion) {
                self::check($result, $date.' metadata.'.$key, $value, $mine);

                continue;
            }
            self::check($result, $date.' metadata.'.$key, $value, $mine[$key] ?? false);
        }
    }

    /**
     * @param  array<string, int>  $buckets
     * @return array<string, int>
     */
    private static function buckets(array $buckets): array
    {
        $buckets = array_filter($buckets, static fn (int $minutes): bool => $minutes !== 0);
        ksort($buckets);

        return $buckets;
    }

    private static function bucketsOf(mixed $mine): mixed
    {
        if ($mine instanceof Undecided) {
            return new Undecided($mine->rules, is_array($mine->literal) ? self::buckets($mine->literal) : $mine->literal);
        }

        return $mine instanceof NoOpinion ? $mine : self::buckets($mine);
    }

    private static function show(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: var_export($value, true);
    }
}
