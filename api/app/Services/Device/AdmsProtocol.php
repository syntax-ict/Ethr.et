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
            'TransFlag=TransData AttLog OpLog',
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
