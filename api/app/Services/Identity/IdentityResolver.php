<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\Employee;
use App\Models\EmployeeExternalIdentity;
use Illuminate\Database\Eloquent\Builder;

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
                    [[
                        'employee_public_id' => $mapping->employee->public_id,
                        'employee_name' => $mapping->employee->name,
                        'score' => $mapping->confidence,
                        'reasons' => ['existing_mapping'],
                    ]],
                );
            }
        }

        $scored = $this->scoreCandidates($tenantId, $signals);

        if ($scored === []) {
            return new IdentityMatch(IdentityMatch::NEW, null, 0.0);
        }

        $top = $scored[0];
        $second = $scored[1] ?? null;

        $candidates = array_map(static fn (array $c): array => [
            'employee_public_id' => $c['employee']->public_id,
            'employee_name' => $c['employee']->name,
            'score' => round($c['score'], 2),
            'reasons' => $c['reasons'],
        ], $scored);

        $ambiguous = $second !== null
            && $second['score'] >= self::THRESHOLD_PROBABLE
            && ($top['score'] - $second['score']) < self::AMBIGUOUS_GAP;

        if ($top['score'] >= self::THRESHOLD_MATCH) {
            $outcome = $ambiguous ? IdentityMatch::AMBIGUOUS : IdentityMatch::MATCHED;
        } elseif ($top['score'] >= self::THRESHOLD_PROBABLE) {
            $outcome = $ambiguous ? IdentityMatch::AMBIGUOUS : IdentityMatch::PROBABLE;
        } else {
            return new IdentityMatch(IdentityMatch::NEW, null, $top['score'], $candidates);
        }

        $employee = $outcome === IdentityMatch::AMBIGUOUS ? null : $top['employee'];

        return new IdentityMatch($outcome, $employee, $top['score'], $candidates);
    }

    /**
     * Persist (or refresh) the mapping from an external identity to an employee.
     * Returns null when the signals carry no external-identity coordinates.
     */
    public function link(Employee $employee, IdentitySignals $signals, float $confidence, bool $verified = false): ?EmployeeExternalIdentity
    {
        if (! $signals->hasExternalIdentity()) {
            return null;
        }

        $existing = $this->findMapping($employee->tenant_id, $signals);

        if ($existing !== null) {
            $existing->update([
                'employee_id' => $employee->id,
                'confidence' => $confidence,
                'verified_at' => $verified ? now() : $existing->verified_at,
            ]);

            return $existing;
        }

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
     * @return array<int, Employee>
     */
    private function fetchCandidates(int $tenantId, IdentitySignals $signals): array
    {
        $code = $this->clean($signals->employeeCode);
        $badge = $this->clean($signals->badgeNumber);
        $email = $this->clean($signals->email);
        $phone = $this->clean($signals->phone);
        $name = $this->clean($signals->name);
        $nationalIdHash = Employee::hashNationalId($signals->nationalId);

        if ($code === null && $badge === null && $email === null && $phone === null && $name === null && $nationalIdHash === null) {
            return [];
        }

        return Employee::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where(function (Builder $q) use ($code, $badge, $email, $phone, $name, $nationalIdHash): void {
                if ($nationalIdHash !== null) {
                    // Blind-index lookup — matches the encrypted national ID by its
                    // deterministic HMAC without decrypting anything.
                    $q->orWhere('national_id_hash', $nationalIdHash);
                }
                if ($code !== null) {
                    $q->orWhereRaw('LOWER(employee_code) = ?', [mb_strtolower($code)]);
                }
                if ($badge !== null) {
                    $q->orWhereRaw('LOWER(badge_number) = ?', [mb_strtolower($badge)]);
                }
                if ($email !== null) {
                    $q->orWhereRaw('LOWER(email) = ?', [mb_strtolower($email)]);
                }
                $normalizedPhone = IdentitySignals::normalizePhone($phone);
                if ($normalizedPhone !== null) {
                    // Compare digit-for-digit so formatting differences (spaces,
                    // dashes, parentheses) don't hide a real match. This does not
                    // reconcile country-code conventions (+251 vs leading 0) —
                    // that is telephony canonicalization, out of scope here.
                    $stripped = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '+', ''), '-', ''), '(', ''), ')', ''), '.', '')";
                    $q->orWhereRaw("{$stripped} = ?", [$normalizedPhone]);
                }
                if ($name !== null) {
                    $q->orWhereRaw('LOWER(name) = ?', [mb_strtolower($name)]);
                }
            })
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

        $phoneA = IdentitySignals::normalizePhone($employee->phone);
        $phoneB = IdentitySignals::normalizePhone($signals->phone);
        if ($phoneA !== null && $phoneA === $phoneB) {
            $score += self::WEIGHT_PHONE;
            $reasons[] = 'phone';
        }

        $similarity = $this->nameSimilarity($employee->name, $signals->name);
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
