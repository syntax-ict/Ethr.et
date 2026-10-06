<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * Parses attendance export files from legacy biometric devices.
 * Supports: BioTime (ZKTeco), Hikvision standalone, and generic CSV formats.
 */
final class AttendanceImportParser
{
    /**
     * Auto-detect format and parse.
     *
     * @return array{format: string, records: array<int, array{badge: string, datetime: string, direction: string}>, errors: string[]}
     */
    public function parse(string $content): array
    {
        $content = str_replace("\r\n", "\n", $content);

        if ($this->isBioTimeFormat($content)) {
            return $this->parseBioTime($content);
        }

        if ($this->isHikvisionFormat($content)) {
            return $this->parseHikvision($content);
        }

        return $this->parseGenericCsv($content);
    }

    // ─────────────────────────────────────────────
    //  BioTime (ZKTeco ICS/Pro) format
    //  Columns: No., ID, Name, Date/Time, Verify, Event, Work Code
    // ─────────────────────────────────────────────
    private function isBioTimeFormat(string $content): bool
    {
        $firstLine = strtolower(trim(explode("\n", $content)[0] ?? ''));

        return str_contains($firstLine, 'verify') || str_contains($firstLine, 'work code');
    }

    /** @return array{format: string, records: list<array{badge: string, datetime: string, direction: string}>, errors: string[]} */
    private function parseBioTime(string $content): array
    {
        $lines = array_filter(explode("\n", $content));
        $headers = array_map('trim', str_getcsv(array_shift($lines)));
        $headers = array_map('strtolower', $headers);

        $idCol = $this->findColumn($headers, ['id', 'user id', 'emp_id', 'badge']);
        $timeCol = $this->findColumn($headers, ['date/time', 'datetime', 'check time', 'time']);
        $eventCol = $this->findColumn($headers, ['event', 'state', 'direction', 'in/out']);

        $records = [];
        $errors = [];

        foreach ($lines as $i => $line) {
            $row = array_map('trim', str_getcsv($line));
            if (count($row) < 2) {
                continue;
            }

            $badge = $idCol !== null ? ($row[$idCol] ?? '') : '';
            $datetime = $timeCol !== null ? ($row[$timeCol] ?? '') : '';
            $direction = $eventCol !== null ? ($row[$eventCol] ?? '') : 'check_in';

            if (empty($badge) || empty($datetime)) {
                $errors[] = 'Line '.($i + 2).': missing badge or datetime';

                continue;
            }

            $parsedTime = $this->parseDateTime($datetime);
            if (! $parsedTime) {
                $errors[] = 'Line '.($i + 2).": unrecognised datetime '{$datetime}'";

                continue;
            }

            $records[] = [
                'badge' => $badge,
                'datetime' => $parsedTime,
                'direction' => $this->normaliseDirection($direction),
            ];
        }

        return ['format' => 'biotime', 'records' => $records, 'errors' => $errors];
    }

    // ─────────────────────────────────────────────
    //  Hikvision standalone export format
    //  Columns: Employee ID, Employee Name, Time, Event Type (0=in, 1=out)
    // ─────────────────────────────────────────────
    private function isHikvisionFormat(string $content): bool
    {
        $firstLine = strtolower(trim(explode("\n", $content)[0] ?? ''));

        return str_contains($firstLine, 'event type') || str_contains($firstLine, 'hikvision');
    }

    /** @return array{format: string, records: list<array{badge: string, datetime: string, direction: string}>, errors: string[]} */
    private function parseHikvision(string $content): array
    {
        $lines = array_filter(explode("\n", $content));
        $headers = array_map('strtolower', array_map('trim', str_getcsv(array_shift($lines))));

        $idCol = $this->findColumn($headers, ['employee id', 'card no.', 'card no', 'id']);
        $timeCol = $this->findColumn($headers, ['time', 'date time', 'datetime', 'swipe time']);
        $typeCol = $this->findColumn($headers, ['event type', 'type', 'event']);

        $records = [];
        $errors = [];

        foreach ($lines as $i => $line) {
            $row = array_map('trim', str_getcsv($line));
            if (count($row) < 2) {
                continue;
            }

            $badge = $idCol !== null ? ($row[$idCol] ?? '') : '';
            $datetime = $timeCol !== null ? ($row[$timeCol] ?? '') : '';
            $type = $typeCol !== null ? ($row[$typeCol] ?? '0') : '0';

            if (empty($badge) || empty($datetime)) {
                $errors[] = 'Line '.($i + 2).': missing badge or datetime';

                continue;
            }

            $parsedTime = $this->parseDateTime($datetime);
            if (! $parsedTime) {
                $errors[] = 'Line '.($i + 2).": unrecognised datetime '{$datetime}'";

                continue;
            }

            $records[] = [
                'badge' => $badge,
                'datetime' => $parsedTime,
                'direction' => $type === '1' ? 'check_out' : 'check_in',
            ];
        }

        return ['format' => 'hikvision', 'records' => $records, 'errors' => $errors];
    }

    // ─────────────────────────────────────────────
    //  Generic CSV: badge_number, datetime, direction
    // ─────────────────────────────────────────────
    /** @return array{format: string, records: list<array{badge: string, datetime: string, direction: string}>, errors: string[]} */
    private function parseGenericCsv(string $content): array
    {
        $lines = array_filter(explode("\n", $content));
        $headers = array_map('strtolower', array_map('trim', str_getcsv(array_shift($lines))));

        $idCol = $this->findColumn($headers, ['badge', 'badge_number', 'id', 'employee_id', 'card']);
        $timeCol = $this->findColumn($headers, ['datetime', 'date_time', 'timestamp', 'time', 'date']);
        $dirCol = $this->findColumn($headers, ['direction', 'type', 'event', 'in_out']);

        $records = [];
        $errors = [];

        foreach ($lines as $i => $line) {
            $row = array_map('trim', str_getcsv($line));
            if (count($row) < 2) {
                continue;
            }

            $badge = $idCol !== null ? ($row[$idCol] ?? '') : ($row[0] ?? '');
            $datetime = $timeCol !== null ? ($row[$timeCol] ?? '') : ($row[1] ?? '');
            $direction = $dirCol !== null ? ($row[$dirCol] ?? 'check_in') : 'check_in';

            if (empty($badge) || empty($datetime)) {
                $errors[] = 'Line '.($i + 2).': missing badge or datetime';

                continue;
            }

            $parsedTime = $this->parseDateTime($datetime);
            if (! $parsedTime) {
                $errors[] = 'Line '.($i + 2).": unrecognised datetime '{$datetime}'";

                continue;
            }

            $records[] = [
                'badge' => $badge,
                'datetime' => $parsedTime,
                'direction' => $this->normaliseDirection($direction),
            ];
        }

        return ['format' => 'generic_csv', 'records' => $records, 'errors' => $errors];
    }

    // ─────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────

    private function findColumn(array $headers, array $candidates): ?int
    {
        foreach ($candidates as $candidate) {
            $idx = array_search($candidate, $headers, true);
            if ($idx !== false) {
                return (int) $idx;
            }
        }

        return null;
    }

    private function parseDateTime(string $raw): ?string
    {
        $formats = [
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'd/m/Y H:i:s',
            'd/m/Y H:i',
            'm/d/Y H:i:s',
            'm/d/Y H:i',
            'Y/m/d H:i:s',
            'Y/m/d H:i',
            'd-m-Y H:i:s',
            'd-m-Y H:i',
        ];

        foreach ($formats as $format) {
            $dt = \DateTime::createFromFormat($format, trim($raw));
            if ($dt !== false) {
                return $dt->format('Y-m-d H:i:s');
            }
        }

        // Fallback: PHP's strtotime
        $ts = strtotime($raw);
        if ($ts !== false) {
            return date('Y-m-d H:i:s', $ts);
        }

        return null;
    }

    private function normaliseDirection(string $raw): string
    {
        $lower = strtolower(trim($raw));
        if (in_array($lower, ['in', 'check_in', 'checkin', 'entry', '0', 'check in'], true)) {
            return 'check_in';
        }
        if (in_array($lower, ['out', 'check_out', 'checkout', 'exit', '1', 'check out'], true)) {
            return 'check_out';
        }

        return 'check_in';
    }
}
