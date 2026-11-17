<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Models\Device;
use App\Models\Employee;
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
        $theirs = $this->words($field);

        foreach ($words as $position => $word) {
            $other = $theirs[$position] ?? null;
            if ($other === null || ! (str_starts_with($other, $word) || str_starts_with($word, $other))) {
                return false;
            }
        }

        return true;
    }
}
