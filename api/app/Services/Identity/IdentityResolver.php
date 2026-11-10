<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\EmployeeStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeExternalIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Resolves external signals to an existing employee, so imports and device syncs
 * never create a duplicate person and every external identity maps to one master
 * record. See ONBOARDING_V2.md decision D4.
 *
 * Matching is deterministic and weighted. Exact hits on near-unique identifiers
 * (employee code, badge, email) are near-certain; a shared-ish phone is weaker;
 * name similarity alone is never enough to claim a match. The weights are chosen
 * so a single strong identifier clears MATCH while name-only stays below
 * PROBABLE — which keeps bulk import from silently merging distinct new hires
 * who happen to have similar names.
 *
 * Weights only add, so on their own they cannot tell "the same person" from
 * "shares one identifier with them". A match that scores as certain is checked
 * for a contradiction (contradictions()) and, with one, is PROBABLE: shown to a
 * person, never acted on. An archived employee is looked at only when nobody
 * current is credible, and is reported as ARCHIVED rather than as a new person.
 *
 * All queries are tenant-scoped explicitly and bypass the global scope, so the
 * resolver is safe from a queued job where no tenant is resolved.
 */
final class IdentityResolver
{
    private const WEIGHT_NATIONAL_ID = 0.95;

    private const WEIGHT_EMPLOYEE_CODE = 0.9;

    private const WEIGHT_BADGE = 0.85;

    private const WEIGHT_EMAIL = 0.85;

    private const WEIGHT_PHONE = 0.7;

    private const WEIGHT_NAME_MAX = 0.2;

    private const NAME_SIMILARITY_FLOOR = 0.6;

    private const THRESHOLD_MATCH = 0.85;

    private const THRESHOLD_PROBABLE = 0.6;

    /** Two candidates within this of each other (both credible) are ambiguous. */
    private const AMBIGUOUS_GAP = 0.15;

    private const MAX_CANDIDATES = 25;

    public function resolve(int $tenantId, IdentitySignals $signals): IdentityMatch
    {
        // A known external mapping is authoritative — no scoring needed.
        if ($signals->hasExternalIdentity()) {
            $mapping = $this->findMapping($tenantId, $signals);

            if ($mapping !== null && $mapping->employee !== null) {
                return new IdentityMatch(
                    IdentityMatch::MATCHED,
                    $mapping->employee,
                    $mapping->confidence,
                    [$this->candidate($mapping->employee, $mapping->confidence, ['existing_mapping'])],
                );
            }
        }

        $scored = $this->scoreCandidates($tenantId, $signals);

        // Current employees decide the verdict exactly as they did before
        // archived ones were fetched at all: an archived holder of the same
        // number must not turn a clear match into a tie.
        $live = array_values(array_filter($scored, static fn (array $c): bool => ! $c['employee']->trashed()));
        $archived = array_values(array_filter($scored, static fn (array $c): bool => $c['employee']->trashed()));

        if ($live === []) {
            return $this->archivedOrNew($archived, $signals, 0.0, []);
        }

        $top = $live[0];
        $second = $live[1] ?? null;

        $candidates = array_map(
            fn (array $c): array => $this->candidate($c['employee'], round($c['score'], 2), $c['reasons']),
            $live,
        );

        $ambiguous = $second !== null
            && $second['score'] >= self::THRESHOLD_PROBABLE
            && ($top['score'] - $second['score']) < self::AMBIGUOUS_GAP;

        if ($top['score'] >= self::THRESHOLD_MATCH) {
            $outcome = $ambiguous ? IdentityMatch::AMBIGUOUS : IdentityMatch::MATCHED;
        } elseif ($top['score'] >= self::THRESHOLD_PROBABLE) {
            $outcome = $ambiguous ? IdentityMatch::AMBIGUOUS : IdentityMatch::PROBABLE;
        } else {
            return $this->archivedOrNew($archived, $signals, $top['score'], $candidates);
        }

        $conflicts = $outcome === IdentityMatch::MATCHED
            ? $this->contradictions($top['employee'], $signals, $top['reasons'])
            : [];

        if ($conflicts !== []) {
            $outcome = IdentityMatch::PROBABLE;
            $candidates[0]['reasons'] = [...$candidates[0]['reasons'], ...$conflicts];
        }

        $employee = $outcome === IdentityMatch::AMBIGUOUS ? null : $top['employee'];

        return new IdentityMatch($outcome, $employee, $top['score'], $candidates, $conflicts);
    }

    /**
     * Persist (or refresh) the mapping from an external identity to an employee.
     * Returns null when the signals carry no external-identity coordinates.
     *
     * A mapping that already names someone else is returned as it stands. This
     * used to re-point it, so a number a person had confirmed as theirs moved
     * to whoever the next match named and nothing recorded that it had. It now
     * moves in two cases only:
     *
     *  - `$reassign`: a person said whose number it is (assigning a held punch,
     *    committing a reviewed migration). Audited, with who held it before.
     *  - nobody confirmed it and its holder is no longer a current record, so
     *    an automatic link follows the match as it always has.
     *
     * It never throws for a holder it will not displace: a device calls this
     * for every punch.
     */
    public function link(Employee $employee, IdentitySignals $signals, float $confidence, bool $verified = false, bool $reassign = false): ?EmployeeExternalIdentity
    {
        if (! $signals->hasExternalIdentity()) {
            return null;
        }

        $existing = $this->findMapping($employee->tenant_id, $signals);

        if ($existing === null) {
            try {
                $identity = new EmployeeExternalIdentity;
                $identity->fill([
                    'tenant_id' => $employee->tenant_id,
                    'employee_id' => $employee->id,
                    'source_type' => $signals->sourceType,
                    'source_ref' => $signals->sourceRef ?? '',
                    'identifier_type' => $signals->identifierType,
                    'identifier_value' => $signals->identifierValue,
                    'confidence' => $confidence,
                    'verified_at' => $verified ? now() : null,
                ]);
                $identity->save();

                return $identity;
            } catch (UniqueConstraintViolationException $e) {
                // Two punches from one new number arrived together and the
                // other one wrote the mapping first.
                $existing = $this->findMapping($employee->tenant_id, $signals);

                if ($existing === null) {
                    throw $e;
                }
            }
        }

        if ($existing->employee_id === $employee->id) {
            $existing->update([
                'confidence' => $confidence,
                'verified_at' => $verified ? now() : $existing->verified_at,
            ]);

            return $existing;
        }

        $holderIsCurrent = $this->employees($employee->tenant_id)->whereKey($existing->employee_id)->exists();
        $followsTheMatch = $existing->verified_at === null && ! $holderIsCurrent;

        if (! $reassign && ! $followsTheMatch) {
            return $existing;
        }

        $previousHolder = $this->employees($employee->tenant_id)
            ->withTrashed()
            ->whereKey($existing->employee_id)
            ->value('public_id');

        $existing->update([
            'employee_id' => $employee->id,
            'confidence' => $confidence,
            'verified_at' => $verified ? now() : null,
        ]);

        if ($reassign) {
            AuditLog::record('identity.reassigned', $employee, [
                'source_type' => $signals->sourceType,
                'source_ref' => $signals->sourceRef ?? '',
                'identifier_type' => $signals->identifierType,
                'identifier_value' => $signals->identifierType === 'national_id' ? null : $signals->identifierValue,
                'from_employee_id' => $previousHolder,
                'to_employee_id' => $employee->public_id,
            ]);
        }

        return $existing;
    }

    private function findMapping(int $tenantId, IdentitySignals $signals): ?EmployeeExternalIdentity
    {
        return EmployeeExternalIdentity::withoutGlobalScope('tenant')
            ->with('employee')
            ->where('tenant_id', $tenantId)
            ->where('source_type', $signals->sourceType)
            ->where('source_ref', $signals->sourceRef ?? '')
            ->where('identifier_type', $signals->identifierType)
            ->where('identifier_value', $signals->identifierValue)
            ->first();
    }

    /**
     * This organisation's employees, with the tenant stated rather than taken
     * from context: a queued job resolves none. Archived employees are left
     * out, as everywhere; a caller that wants them says `withTrashed()`.
     *
     * @return Builder<Employee>
     */
    private function employees(int $tenantId): Builder
    {
        return Employee::withoutGlobalScope('tenant')->where('tenant_id', $tenantId);
    }

    /**
     * One row of the shortlist a review screen shows. `former_employee` and
     * `archived` ride along as reasons, so a screen that lists reasons says
     * where the person stands without a field of its own.
     *
     * @param  array<int, string>  $reasons
     * @return array{employee_public_id: string, employee_name: string, score: float, reasons: array<int, string>}
     */
    private function candidate(Employee $employee, float $score, array $reasons): array
    {
        if ($employee->trashed()) {
            $reasons[] = 'archived';
        } elseif (in_array($employee->status, EmployeeStatus::leaving(), true)) {
            $reasons[] = 'former_employee';
        }

        return [
            'employee_public_id' => $employee->public_id,
            'employee_name' => $employee->name,
            'score' => $score,
            'reasons' => $reasons,
        ];
    }

    /**
     * Nobody current is credible. An archived employee who is a certain match,
     * with nothing contradicting it, is that person: restore them. Anything
     * less is a new person, as it was before archived employees were read.
     *
     * @param  array<int, array{employee: Employee, score: float, reasons: array<int, string>}>  $archived
     * @param  array<int, array{employee_public_id: string, employee_name: string, score: float, reasons: array<int, string>}>  $candidates
     */
    private function archivedOrNew(array $archived, IdentitySignals $signals, float $confidence, array $candidates): IdentityMatch
    {
        $best = $archived[0] ?? null;

        if ($best !== null
            && $best['score'] >= self::THRESHOLD_MATCH
            && $this->contradictions($best['employee'], $signals, $best['reasons']) === []) {
            return new IdentityMatch(
                IdentityMatch::ARCHIVED,
                $best['employee'],
                $best['score'],
                [$this->candidate($best['employee'], round($best['score'], 2), $best['reasons'])],
            );
        }

        return new IdentityMatch(IdentityMatch::NEW, null, $confidence, $candidates);
    }

    /**
     * Why a match that scored as certain is not one.
     *
     *  - `national_id_differs`: both have a national ID and they are not the
     *    same. Nothing else outweighs that.
     *  - `employee_code_differs`: the source states an employee number and the
     *    employee has another. Not for a number a terminal supplied, which was
     *    only ever tried as one (IdentitySignals::$codeIsGuess). And not when
     *    the national ID agrees: an organisation renumbers its staff, a person
     *    does not get a second national ID (NationalIdMatchingTest).
     *  - `name_differs`: the match rests on a device number alone — a badge,
     *    or a terminal's own number tried as an employee number — and the two
     *    names share no word. Small numbers collide; "Guest card" under
     *    number 12 is not the employee whose number is 12. A punch carries no
     *    name, so this never applies to one.
     *
     * @param  array<int, string>  $reasons
     * @return list<string>
     */
    private function contradictions(Employee $employee, IdentitySignals $signals, array $reasons): array
    {
        $conflicts = [];

        $nationalIdHash = Employee::hashNationalId($signals->nationalId);
        if ($nationalIdHash !== null && $employee->national_id_hash !== null && $employee->national_id_hash !== $nationalIdHash) {
            $conflicts[] = 'national_id_differs';
        }

        if (! $signals->codeIsGuess
            && ! in_array('national_id', $reasons, true)
            && $this->clean($signals->employeeCode) !== null
            && $this->clean($employee->employee_code) !== null
            && ! $this->ciEquals($employee->employee_code, $signals->employeeCode)) {
            $conflicts[] = 'employee_code_differs';
        }

        $identifiers = array_intersect($reasons, ['national_id', 'employee_code', 'badge_number', 'email', 'phone']);
        $deviceNumbers = $signals->codeIsGuess ? ['employee_code', 'badge_number'] : ['badge_number'];

        if ($identifiers !== []
            && array_diff($identifiers, $deviceNumbers) === []
            && $this->namesShareNothing($employee, $signals->name)) {
            $conflicts[] = 'name_differs';
        }

        return $conflicts;
    }

    /**
     * The two names are written in the same script and have no word in common.
     *
     * Deliberately not a similarity score. A terminal holds "ABEBE", "Abebe K."
     * or "Abebe 2" for Abebe Kebede Tesfaye, and each of those scores below any
     * useful threshold while plainly being him; what marks a different person
     * is that nothing overlaps at all. An Amharic name against a record that
     * has only a Latin one cannot be compared, and is not called different.
     */
    private function namesShareNothing(Employee $employee, ?string $name): bool
    {
        $incoming = $this->nameWords($name);
        if ($incoming === []) {
            return false;
        }

        $compared = false;

        foreach ([$employee->name, $employee->name_am] as $known) {
            $words = $this->nameWords($known);
            if ($words === [] || $this->isEthiopic($known) !== $this->isEthiopic($name)) {
                continue;
            }

            $compared = true;

            foreach ($incoming as $a) {
                foreach ($words as $b) {
                    if ($this->sameWord($a, $b)) {
                        return false;
                    }
                }
            }
        }

        return $compared;
    }

    /** @return list<string> */
    private function nameWords(?string $name): array
    {
        if ($name === null) {
            return [];
        }

        $words = preg_split('/[^\p{L}\p{M}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : $words;
    }

    private function isEthiopic(?string $text): bool
    {
        return $text !== null && preg_match('/\p{Ethiopic}/u', $text) === 1;
    }

    /** Equal, an initial or truncation of the other, or one transliteration apart. */
    private function sameWord(string $a, string $b): bool
    {
        if ($a === $b || str_starts_with($a, $b) || str_starts_with($b, $a)) {
            return true;
        }

        if (mb_strlen($a) < 4 || mb_strlen($b) < 4) {
            return false;
        }

        similar_text($a, $b, $percent);

        return $percent >= 80.0;
    }

    /**
     * @return array<int, array{employee: Employee, score: float, reasons: array<int, string>}>
     */
    private function scoreCandidates(int $tenantId, IdentitySignals $signals): array
    {
        $candidates = $this->fetchCandidates($tenantId, $signals);
        $scored = [];

        foreach ($candidates as $employee) {
            [$score, $reasons] = $this->score($employee, $signals);

            if ($score > 0.0) {
                $scored[] = ['employee' => $employee, 'score' => min($score, 1.0), 'reasons' => $reasons];
            }
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $scored;
    }

    /**
     * Candidate employees for scoring — one tenant-scoped query, six OR'd
     * predicates.
     *
     * **`badge_number`, `email` and `name` keep their `LOWER()` wrappers, and
     * that is deliberate.** The obvious tidy-up is to give them generated
     * columns like `employee_code_normalized` and stop wrapping. It would buy
     * nothing, because this query cannot use an index on any of these columns
     * whatever they look like. Measured with `EXPLAIN QUERY PLAN` on SQLite
     * 3.45.1 against this exact shape — `tenant_id = ? AND (a OR b OR … OR f)`
     * — with three variants: as it stands, with code and phone moved to the
     * generated columns, and with **every** term given an index. All three
     * produce the identical plan:
     *
     *     SEARCH employees USING INDEX <some (tenant_id, …) index> (tenant_id=?)
     *
     * The seek is on `tenant_id` alone; the disjunction is then evaluated row
     * by row. A control query with the same predicate and no `OR` seeks both
     * columns, which is what makes the reading a measurement rather than a
     * guess about the optimiser.
     *
     * So the two changes above are **not** a performance fix and are not
     * claimed as one. They remove a second, hand-written copy of a
     * normalisation rule that already exists as a generated column — the kind
     * of duplication that stays correct until someone changes one of them.
     *
     * If this path ever needs to be fast, the fix is structural, not cosmetic:
     * run one indexed equality per identifier and union the results in PHP,
     * which would also mean deciding what `MAX_CANDIDATES` means across a
     * union rather than a single `LIMIT`. That is a change to matching
     * behaviour and belongs in its own review. `BASELINE.md` §15h.
     *
     * Archived employees are fetched too, after the current ones so the cap
     * never drops a current employee for an archived one. resolve() looks at
     * them only when nobody current is credible.
     *
     * @return array<int, Employee>
     */
    private function fetchCandidates(int $tenantId, IdentitySignals $signals): array
    {
        $code = $this->clean($signals->employeeCode);
        $badge = $this->clean($signals->badgeNumber);
        $email = $this->clean($signals->email);
        $phoneForms = IdentitySignals::phoneForms($signals->phone);
        $name = $this->clean($signals->name);
        $nationalIdHash = Employee::hashNationalId($signals->nationalId);

        if ($code === null && $badge === null && $email === null && $phoneForms === [] && $name === null && $nationalIdHash === null) {
            return [];
        }

        return $this->employees($tenantId)
            ->withTrashed()
            ->where(function (Builder $q) use ($code, $badge, $email, $phoneForms, $name, $nationalIdHash): void {
                if ($nationalIdHash !== null) {
                    // Blind-index lookup — matches the encrypted national ID by its
                    // deterministic HMAC without decrypting anything.
                    $q->orWhere('national_id_hash', $nationalIdHash);
                }
                if ($code !== null) {
                    // `employee_code_normalized`, not `LOWER(employee_code)`: the
                    // column IS `LOWER(employee_code)`, generated, and writing the
                    // expression again here would be a second definition of the
                    // same rule that nothing keeps in step with the first.
                    $q->orWhere('employee_code_normalized', mb_strtolower($code));
                }
                if ($badge !== null) {
                    $q->orWhereRaw('LOWER(badge_number) = ?', [mb_strtolower($badge)]);
                }
                if ($email !== null) {
                    $q->orWhereRaw('LOWER(email) = ?', [mb_strtolower($email)]);
                }
                if ($phoneForms !== []) {
                    // Digit-for-digit against `phone_normalized`, in every form
                    // one Ethiopian number is written: a file's `0911…` is the
                    // `+251911…` this system stores, and a row written before
                    // phones were canonicalised holds whichever its source
                    // used. Until 2026-11-10 only the form as typed was tried,
                    // so the two never met and a re-imported person was new.
                    //
                    // The asymmetry BASELINE §15e records for the login path is
                    // still here: the column strips six characters and
                    // phoneForms() every non-digit, so a number stored as
                    // `091/234-5678` has never matched.
                    $q->orWhereIn('phone_normalized', $phoneForms);
                }
                if ($name !== null) {
                    $q->orWhereRaw('LOWER(name) = ?', [mb_strtolower($name)]);
                }
            })
            ->orderByRaw('deleted_at IS NULL DESC')
            ->limit(self::MAX_CANDIDATES)
            ->get()
            ->all();
    }

    /**
     * @return array{0: float, 1: array<int, string>}
     */
    private function score(Employee $employee, IdentitySignals $signals): array
    {
        $score = 0.0;
        $reasons = [];

        $nationalIdHash = Employee::hashNationalId($signals->nationalId);
        if ($nationalIdHash !== null && $employee->national_id_hash === $nationalIdHash) {
            $score += self::WEIGHT_NATIONAL_ID;
            $reasons[] = 'national_id';
        }

        if ($this->ciEquals($employee->employee_code, $signals->employeeCode)) {
            $score += self::WEIGHT_EMPLOYEE_CODE;
            $reasons[] = 'employee_code';
        }

        if ($this->ciEquals($employee->badge_number, $signals->badgeNumber)) {
            $score += self::WEIGHT_BADGE;
            $reasons[] = 'badge_number';
        }

        if ($this->ciEquals($employee->email, $signals->email)) {
            $score += self::WEIGHT_EMAIL;
            $reasons[] = 'email';
        }

        if (array_intersect(IdentitySignals::phoneForms($employee->phone), IdentitySignals::phoneForms($signals->phone)) !== []) {
            $score += self::WEIGHT_PHONE;
            $reasons[] = 'phone';
        }

        $similarity = max(
            $this->nameSimilarity($employee->name, $signals->name),
            $this->nameSimilarity($employee->name_am, $signals->name),
        );
        if ($similarity >= self::NAME_SIMILARITY_FLOOR) {
            $score += $similarity * self::WEIGHT_NAME_MAX;
            $reasons[] = 'name~'.round($similarity, 2);
        }

        return [$score, $reasons];
    }

    private function nameSimilarity(?string $a, ?string $b): float
    {
        $a = $this->normalizeName($a);
        $b = $this->normalizeName($b);

        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 1.0;
        }

        similar_text($a, $b, $percent);

        return $percent / 100;
    }

    private function normalizeName(?string $name): string
    {
        if ($name === null) {
            return '';
        }

        $collapsed = preg_replace('/\s+/', ' ', trim($name)) ?? '';

        return mb_strtolower($collapsed);
    }

    private function ciEquals(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }

        $a = trim($a);
        $b = trim($b);

        return $a !== '' && mb_strtolower($a) === mb_strtolower($b);
    }

    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
