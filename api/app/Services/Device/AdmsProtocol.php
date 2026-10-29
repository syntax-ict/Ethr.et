<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Enums\DeviceCommandType;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Support\DeviceTime;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The text formats of ZKTeco's ADMS push protocol (PUSH SDK 2.x), in one place.
 *
 * Written from the published protocol and from what open implementations
 * send; it has not yet been run against a real terminal (field test O1), so
 * the first device should be watched on its Commands tab.
 *
 * - Attendance arrives as `POST /iclock/cdata?table=ATTLOG`, one punch per
 *   line: `PIN \t YYYY-MM-DD HH:MM:SS \t status \t verify \t workcode ...`,
 *   in the device's local time with no offset.
 * - Commands go out in the answer to `GET /iclock/getrequest`, one per line:
 *   `C:<id>:<command>`.
 * - Results come back as `POST /iclock/devicecmd`, one per line:
 *   `ID=<id>&Return=<code>&CMD=<name>`; 0 is success.
 */
final class AdmsProtocol
{
    /** The PUSH 3.x form of the roster request, sent when a device refuses the 2.x one. */
    public const QUERY_V3 = 'v3';

    /**
     * The options a device asks for when it starts (`GET /iclock/cdata`).
     *
     * `Stamp=None` asks for everything the device holds, so a first connection
     * also uploads its stored punches; they dedupe by idempotency key.
     * `Realtime=1` sends each punch as it happens, which is what lets
     * DeviceClock read the device's clock off it.
     */
    public static function handshake(Device $device): string
    {
        $offsetHours = Carbon::now(DeviceTime::zone($device))->getOffset() / 3600;

        return implode("\n", [
            'GET OPTION FROM: '.$device->serial_number,
            'ATTLOGStamp=None',
            'OPERLOGStamp=9999',
            'ATTPHOTOStamp=None',
            'ErrorDelay=60',
            'Delay=30',
            'TransTimes=00:00;14:05',
            'TransInterval=1',
            // EnrollUser and ChgUser: a person enrolled or changed at the
            // terminal is uploaded as it happens, so the roster stays current
            // between requests. Not EnrollFP: ETHR never wants the templates.
            'TransFlag=TransData AttLog OpLog EnrollUser ChgUser',
            'TimeZone='.(fmod($offsetHours, 1.0) === 0.0 ? (int) $offsetHours : $offsetHours),
            'Realtime=1',
            'Encrypt=None',
        ])."\n";
    }

    /**
     * @return list<array{pin: string, time: string, status: string|null}>
     */
    public static function attendanceLines(string $body): array
    {
        $punches = [];
        foreach (preg_split('/\r\n|\n|\r/', $body) ?: [] as $line) {
            $fields = explode("\t", trim($line));
            if (count($fields) < 2 || $fields[0] === '' || ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $fields[1])) {
                continue;
            }

            $punches[] = ['pin' => $fields[0], 'time' => $fields[1], 'status' => $fields[2] ?? null];
        }

        return $punches;
    }

    /**
     * The people in an upload, keyed by PIN. Two generations of firmware:
     *
     * - PUSH 2.x, `POST /iclock/cdata?table=OPERLOG` (or `table=USERINFO`):
     *   `USER PIN=1 \t Name=Abebe \t Pri=0 \t Passwd= \t Card=[0000123456] ...`,
     *   with `FP PIN=1 \t FID=6 ...` and `FACE PIN=1 ...` lines for templates.
     * - PUSH 3.x, `POST /iclock/querydata?tablename=user` (or `cdata?table=tabledata`):
     *   `user uid=1 \t pin=1 \t name=Abebe \t privilege=0 \t password= \t cardno=123456 ...`,
     *   with `templatev10 pin=1 ...` and `biodata pin=1 \t type=1 ...` lines.
     *
     * Keys are read without regard to case, fields split on tabs (or, from
     * firmware that uses spaces, before each `Key=`). A password is never
     * read out of the line, and a template line only counts towards its PIN:
     * neither leaves this method.
     *
     * `user` is false for a PIN that only had template lines in this upload,
     * so the caller updates counts without inventing a person.
     *
     * @return array<string, array{pin: string, user: bool, name: string|null, card: string|null, is_admin: bool, fingerprints: int, face: bool}>
     */
    public static function userLines(string $body): array
    {
        $people = [];
        $fingers = [];

        foreach (preg_split('/\r\n|\n|\r/', $body) ?: [] as $line) {
            $line = trim($line);
            if (! preg_match('/^([A-Za-z0-9]+)\s+(.*)$/s', $line, $m)) {
                continue;
            }

            $kind = strtolower($m[1]);
            $fields = self::keyValues($m[2]);
            $pin = trim((string) ($fields['pin'] ?? ''));
            if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $pin)) {
                continue;
            }

            $people[$pin] ??= ['pin' => $pin, 'user' => false, 'name' => null, 'card' => null, 'is_admin' => false, 'fingerprints' => 0, 'face' => false];

            if ($kind === 'user') {
                $people[$pin]['user'] = true;
                $people[$pin]['name'] = self::name($fields['name'] ?? null);
                $people[$pin]['card'] = self::card($fields['card'] ?? $fields['cardno'] ?? null);
                $people[$pin]['is_admin'] = (int) ($fields['pri'] ?? $fields['privilege'] ?? 0) > 0;
            } elseif (in_array($kind, ['fp', 'templatev10', 'fingertmp'], true)
                || ($kind === 'biodata' && (string) ($fields['type'] ?? '') === '1')) {
                // One finger may be sent twice (a resend, or two formats);
                // count fingers, not lines.
                $finger = (string) ($fields['fid'] ?? $fields['fingerid'] ?? $fields['no'] ?? count($fingers[$pin] ?? []));
                $fingers[$pin][$finger] = true;
            } elseif (in_array($kind, ['face', 'biodata', 'biophoto'], true)) {
                $people[$pin]['face'] = true;
            }
        }

        foreach ($fingers as $pin => $set) {
            $people[$pin]['fingerprints'] = count($set);
        }

        return $people;
    }

    /**
     * `Key=value` pairs from one line, keys lowercased. Split on tabs; a line
     * with no tab is split on the whitespace before each `Key=`, so a name
     * with a space in it stays whole.
     *
     * @return array<string, string>
     */
    private static function keyValues(string $rest): array
    {
        $parts = str_contains($rest, "\t")
            ? explode("\t", $rest)
            : (preg_split('/\s+(?=[A-Za-z][A-Za-z0-9]*=)/', $rest) ?: []);

        $fields = [];
        foreach ($parts as $part) {
            $eq = strpos($part, '=');
            if ($eq === false || $eq === 0) {
                continue;
            }
            $fields[strtolower(trim(substr($part, 0, $eq)))] = substr($part, $eq + 1);
        }

        return $fields;
    }

    /** Valid UTF-8, no control characters, at most 100 characters; null when empty. */
    private static function name(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', mb_scrub($value, 'UTF-8')) ?? '');

        return $clean === '' ? null : mb_substr($clean, 0, 100);
    }

    /**
     * A card without the brackets 2.x firmware wraps it in, and without the
     * zeros it pads a numeric card with (`[0000123456]` is card 123456, the
     * number printed on it). None when blank or all zeros.
     */
    private static function card(?string $value): ?string
    {
        $card = trim((string) $value, " []\t");
        if (ctype_digit($card)) {
            $card = ltrim($card, '0');
        }

        return $card === '' || ! preg_match('/^[A-Za-z0-9]{1,40}$/', $card) ? null : $card;
    }

    /**
     * @return list<array{id: int, return: int}>
     */
    public static function commandResults(string $body): array
    {
        $results = [];
        foreach (preg_split('/\r\n|\n|\r/', $body) ?: [] as $line) {
            parse_str(trim($line), $fields);
            if (isset($fields['ID'], $fields['Return']) && is_numeric($fields['ID']) && is_numeric($fields['Return'])) {
                $results[] = ['id' => (int) $fields['ID'], 'return' => (int) $fields['Return']];
            }
        }

        return $results;
    }

    /** One command as the device reads it. */
    public static function command(Device $device, DeviceCommand $command): string
    {
        $payload = $command->payload ?? [];
        $pin = self::field((string) ($payload['device_user_id'] ?? ''));

        $body = match ($command->type) {
            DeviceCommandType::UPSERT_USER => "DATA UPDATE USERINFO PIN={$pin}\tName=".self::field((string) ($payload['name'] ?? ''))."\tPri=0",
            DeviceCommandType::DELETE_USER => "DATA DELETE USERINFO PIN={$pin}",
            DeviceCommandType::SET_TIME => 'SET OPTION DateTime='.self::encodeTime(Carbon::now(DeviceTime::zone($device))),
            DeviceCommandType::QUERY_USERS => ($payload['format'] ?? null) === self::QUERY_V3
                ? 'DATA QUERY tablename=user,fielddesc=*,filter=*'
                : 'DATA QUERY USERINFO',
        };

        return "C:{$command->id}:{$body}";
    }

    /**
     * ZKTeco's packed time: seconds in a calendar of 12 months of 31 days from
     * 2000-01-01, the encoding its firmware uses for DateTime.
     */
    public static function encodeTime(CarbonInterface $local): int
    {
        return ((($local->year % 100) * 12 * 31 + ($local->month - 1) * 31 + $local->day - 1) * 86400)
            + ($local->hour * 3600) + ($local->minute * 60) + $local->second;
    }

    /** A value cannot carry the tab or newline that separate fields and commands. */
    private static function field(string $value): string
    {
        return trim(preg_replace('/[\t\r\n]+/', ' ', $value) ?? '');
    }
}
