<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Models\Device;
use App\Models\Employee;
use App\Support\PersonName;
use Illuminate\Support\Facades\DB;

/**
 * The names a terminal gives for its user numbers.
 *
 * A Hikvision terminal sends the enrolled person's name beside the number on
 * every event. ETHR used to keep only the number, so a number that matched no
 * employee could not be traced to a person. The name is kept in
 * `device_enrollment_snapshots`, the table that already holds what a terminal
 * says about the people on it, and is used only to show who a held badge may
 * be. Nothing here assigns a badge.
 */
final class TerminalNames
{
    /**
     * Remember the name a terminal sent for a user number. One statement, and
     * only the name and the time are touched, so a row an ADMS roster wrote
     * keeps its card, admin flag and template counts. A name must never cost
     * a punch, so a failure is reported and swallowed.
     */
    public function note(Device $device, string $badge, ?string $name): void
    {
        if ($name === null) {
            return;
        }

        try {
            $now = now();

            DB::table('device_enrollment_snapshots')->upsert(
                [[
                    'tenant_id' => $device->tenant_id,
                    'device_id' => $device->id,
                    'device_user_id' => mb_substr($badge, 0, 64),
                    'name' => mb_substr($name, 0, 100),
                    'seen_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]],
                ['device_id', 'device_user_id'],
                ['name', 'seen_at', 'updated_at'],
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * The names held for these (device, badge) pairs, read as
     * `$names[$deviceId][mb_strtolower($badge)]`. One query, this organisation
     * only. The query matches as the index's collation does (MariaDB ignores
     * case, so a row kept as "r011" answers for a held "R011"); the map is
     * keyed by the lowercased badge so the reader finds it either way.
     *
     * @param  list<array{0: int, 1: string}>  $pairs
     * @return array<int, array<string, string>>
     */
    public function forBadges(int $tenantId, array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        $deviceIds = array_values(array_unique(array_column($pairs, 0)));
        // Both spellings, so an engine that compares case-sensitively (the
        // SQLite of the test suite) finds what MariaDB's collation would.
        $badges = [];
        foreach ($pairs as $pair) {
            $badge = mb_substr($pair[1], 0, 64);
            $badges[$badge] = true;
            $badges[mb_strtolower($badge)] = true;
        }
        $badges = array_map('strval', array_keys($badges));

        $rows = DB::table('device_enrollment_snapshots')
            ->where('tenant_id', $tenantId)
            ->whereIn('device_id', $deviceIds)
            ->whereIn('device_user_id', $badges)
            ->whereNotNull('name')
            ->get(['device_id', 'device_user_id', 'name']);

        $names = [];
        foreach ($rows as $row) {
            $names[(int) $row->device_id][mb_strtolower((string) $row->device_user_id)] = (string) $row->name;
        }

        return $names;
    }

    /**
     * Current employees who could be the person a terminal calls `$name`.
     *
     * Ethiopian names run given name, father's name, grandfather's name, so the
     * terminal's words must match the employee's from the first, in order:
     * "Abebe Kebede" fits "Abebe Kebede Tesfaye", and "Kebede Tesfaye" (the
     * father) does not fit his child.
     *
     * A single given name among thousands of people is noise, so fewer than
     * two words suggests nobody; so does a name that more than `$limit` people
     * fit, because a list that long helps no one choose.
     *
     * @return list<Employee>
     */
    public function lookalikes(int $tenantId, string $name, int $limit = 5): array
    {
        $words = $this->words($name);
        if (count($words) < 2) {
            return [];
        }

        // The words are letters and marks only (see words()), so they carry no
        // LIKE wildcard and need no escaping.
        $everyWordIn = function (string $column) use ($words) {
            return function ($query) use ($column, $words): void {
                foreach ($words as $word) {
                    $query->whereRaw("LOWER({$column}) LIKE ?", ['%'.$word.'%']);
                }
            };
        };

        $cap = max(50, $limit + 1);
        $candidates = Employee::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($everyWordIn): void {
                $query->where($everyWordIn('name'))
                    ->orWhere($everyWordIn('name_am'));
            })
            ->with('department')
            ->limit($cap)
            ->get();

        // A full page may have been cut short, so the list below could be
        // partial; say nothing rather than a wrong short list.
        if ($candidates->count() >= $cap) {
            return [];
        }

        // The SQL test is a substring one and the PHP one below is stricter, so
        // the cap sits well above `$limit`: a few loose hits must not crowd out
        // the real match.
        $found = [];
        foreach ($candidates as $employee) {
            if ($this->sharesEveryWord($words, (string) $employee->name) || $this->sharesEveryWord($words, (string) $employee->name_am)) {
                $found[] = $employee;
            }
        }

        return count($found) > $limit ? [] : $found;
    }

    /** The terminal's name is the employee's, word for word. */
    public const FIT_SAME = 'same';

    /** The terminal's name is the employee's, spelled a little differently. */
    public const FIT_SIMILAR = 'similar';

    /**
     * Who each terminal name may be, for a person to read. Never for anything
     * that assigns: the automatic recording asks `lookalikes()` and nothing
     * here.
     *
     * The same positional rule as `lookalikes()`: every terminal word must fit
     * the employee's word in the same place, from the given name, and fewer
     * than two words suggests nobody. What differs is the word test. A word
     * fits when it is the other's start or equal (`FIT_SAME`), or when the two
     * are the same name written differently (`FIT_SIMILAR`): a doubled letter,
     * a swapped vowel, one letter off, or an Ethiopic name against a Latin one
     * (PersonName::fold(), EthiopicLatin). The strict fits are listed first.
     *
     * More than `$limit` fits says nothing, as in `lookalikes()`. When the
     * strict fits alone are within it and the similar ones push it over, the
     * strict ones are kept and the similar ones dropped: a list that grew past
     * five because spellings were forgiven must not take away the line a
     * reader had before.
     *
     * The organisation's current employees are read once for all the names
     * (id, names and the columns an access check reads), so a page of badges
     * costs one query, not one per badge. Every query states the tenant.
     *
     * @param  array<int|string, string>  $names  terminal name by caller's key
     * @return array<int|string, list<array{employee: Employee, fit: string}>>
     */
    public function suggestionsFor(int $tenantId, array $names, int $limit = 5): array
    {
        $result = [];
        $wanted = [];
        foreach ($names as $key => $name) {
            $words = $this->words($name);
            $result[$key] = [];
            if (count($words) >= 2) {
                $wanted[$key] = $words;
            }
        }

        if ($wanted === []) {
            return $result;
        }

        $pool = $this->pool($tenantId);

        foreach ($wanted as $key => $words) {
            $folded = array_map(PersonName::fold(...), $words);
            $strict = [];
            $similar = [];

            foreach ($pool as $entry) {
                if ($this->prefixFits($words, $entry['plain'][0]) || $this->prefixFits($words, $entry['plain'][1])) {
                    $strict[] = $entry['employee'];
                } elseif ($this->looselyFits($folded, $entry['folded'][0]) || $this->looselyFits($folded, $entry['folded'][1])) {
                    $similar[] = $entry['employee'];
                }
            }

            if (count($strict) > $limit) {
                continue;
            }

            $kept = count($strict) + count($similar) > $limit ? [] : $similar;

            $result[$key] = [
                ...array_map(fn (Employee $e): array => ['employee' => $e, 'fit' => self::FIT_SAME], $strict),
                ...array_map(fn (Employee $e): array => ['employee' => $e, 'fit' => self::FIT_SIMILAR], $kept),
            ];
        }

        return $result;
    }

    /**
     * One name's suggestions; see `suggestionsFor()`.
     *
     * @return list<array{employee: Employee, fit: string}>
     */
    public function suggestions(int $tenantId, string $name, int $limit = 5): array
    {
        return $this->suggestionsFor($tenantId, [0 => $name], $limit)[0];
    }

    /**
     * The organisation's current employees with their names split into words,
     * plain and folded, once. The columns are what `canAccessEmployee()` reads
     * and what the card shows of a person before the identity line loads.
     *
     * @return list<array{employee: Employee, plain: array{0: list<string>, 1: list<string>}, folded: array{0: list<string>, 1: list<string>}}>
     */
    private function pool(int $tenantId): array
    {
        $pool = [];

        Employee::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->get(['id', 'tenant_id', 'public_id', 'name', 'name_am', 'department_id', 'branch_id', 'team_id', 'supervisor_id'])
            ->each(function (Employee $employee) use (&$pool): void {
                $plain = [$this->words((string) $employee->name), $this->words((string) $employee->name_am)];

                $pool[] = [
                    'employee' => $employee,
                    'plain' => $plain,
                    'folded' => [array_map(PersonName::fold(...), $plain[0]), array_map(PersonName::fold(...), $plain[1])],
                ];
            });

        return $pool;
    }

    /**
     * Terminal word i fits the employee's word i for every terminal word, each
     * pair the same name once spellings are folded.
     *
     * @param  list<string>  $folded
     * @param  list<string>  $theirs  already folded
     */
    private function looselyFits(array $folded, array $theirs): bool
    {
        foreach ($folded as $position => $word) {
            $other = $theirs[$position] ?? null;
            if ($other === null || ! PersonName::foldedWordsFit($word, $other)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function words(string $name): array
    {
        $parts = preg_split('/[^\p{L}\p{M}]+/u', mb_strtolower($name)) ?: [];

        return array_values(array_filter($parts, fn (string $word): bool => mb_strlen($word) >= 2));
    }

    /**
     * Terminal word i matches the employee's word i, for every terminal word:
     * one is the start of, or equal to, the other. "Abebe Kebede" fits
     * "Abebe Kebede Tesfaye" and "Abebe Kebedew X", not "Kebede Abebe",
     * "Tigist Abebe Kebede" or "Abebe Tesfaye Kebede".
     *
     * @param  list<string>  $words
     */
    private function sharesEveryWord(array $words, string $field): bool
    {
        return $this->prefixFits($words, $this->words($field));
    }

    /**
     * @param  list<string>  $words
     * @param  list<string>  $theirs
     */
    private function prefixFits(array $words, array $theirs): bool
    {
        foreach ($words as $position => $word) {
            $other = $theirs[$position] ?? null;
            if ($other === null || ! (str_starts_with($other, $word) || str_starts_with($word, $other))) {
                return false;
            }
        }

        return true;
    }
}
