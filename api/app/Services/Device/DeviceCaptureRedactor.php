<?php

declare(strict_types=1);

namespace App\Services\Device;

/**
 * Takes a terminal's raw event out of what it can identify, for the platform
 * export that fixtures are written from. Applied to one export, so the same
 * person or number gets the same placeholder throughout it (`PERSON-1`,
 * `BADGE-1`) and a fixture keeps its shape: two events for one badge still
 * name one badge.
 *
 * Nothing is hashed. A badge is a short number, and a hash of a four-digit
 * number is reversed in a moment; a placeholder from a counter says nothing
 * about the number it replaced.
 *
 * What is replaced: every person name, every badge, employee and card
 * number, every field that carries a face or a photograph, every token,
 * password or key, a device address, and the query string of any URL (a
 * token travels in one). The tab-separated line an ADMS terminal sends has
 * its first field, the PIN, replaced; a line ETHR could not read is
 * withheld whole, since nothing says which part is a person.
 */
final class DeviceCaptureRedactor
{
    private const PERSON = '/^(name|username|personname|firstname|lastname|fullname|surname|cardholder)$/i';

    private const BADGE = '/^(employeeno|employeenostring|cardno|cardnumber|pin|badge|userid|personid|workno|idcard|idnumber)$/i';

    private const BINARY = '/(face|photo|picture|image|snapshot|template|fingerprint|biometric|base64|capture)/i';

    private const SECRET = '/(token|password|passwd|secret|apikey|api_key|authorization|signature)/i';

    private const ADDRESS = '/^(ipaddress|ipv4address|ipv6address|macaddress|ip|mac)$/i';

    /** @var array<string, array<string, int>> */
    private array $seen = [];

    public function badge(string $badge): string
    {
        return $badge === '' ? '' : $this->placeholder('BADGE', $badge);
    }

    /**
     * A raw event as stored: JSON text, a tab-separated ADMS line, or the
     * truncated start of either.
     */
    public function raw(?string $raw, ?string $failure = null): ?string
    {
        if ($raw === null || $raw === '') {
            return $raw;
        }

        if ($failure === 'unreadable') {
            return '[line withheld: could not be read]';
        }

        $trimmed = ltrim($raw);

        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            $decoded = json_decode($raw, true);

            return is_array($decoded)
                ? (string) json_encode($this->value($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $this->textual($raw);
        }

        if (str_contains($raw, "\t")) {
            $fields = explode("\t", $raw);
            $fields[0] = $this->placeholder('BADGE', trim($fields[0]));

            return implode("\t", array_map(fn (string $f) => $this->stripQuery($f), $fields));
        }

        return $this->textual($raw);
    }

    /**
     * @param  mixed  $value  a decoded body
     */
    public function value(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->value($v, is_string($k) ? $k : $key);
            }

            return $out;
        }

        if ($key !== null) {
            $category = $this->category($key);
            if ($category !== null && $value !== null && $value !== '') {
                return $category === 'ADDR' ? '[address]' : $this->placeholder($category, (string) $value);
            }
        }

        if (is_string($value)) {
            // A form field that carries more JSON (Hikvision's `event_log`).
            $trimmed = ltrim($value);
            if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    return (string) json_encode($this->value($decoded, $key), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }

            return $this->stripQuery($value);
        }

        return $value;
    }

    private function category(string $key): ?string
    {
        return match (true) {
            preg_match(self::ADDRESS, $key) === 1 => 'ADDR',
            preg_match(self::SECRET, $key) === 1 => 'SECRET',
            preg_match(self::BINARY, $key) === 1 => 'BINARY',
            preg_match(self::BADGE, $key) === 1 => 'BADGE',
            preg_match(self::PERSON, $key) === 1 => 'PERSON',
            default => null,
        };
    }

    /** Text that would not decode: the same keys, found by pattern. */
    private function textual(string $text): string
    {
        $keys = 'name|userName|personName|employeeNo|employeeNoString|cardNo|cardNumber|card|pin|badge|userId|token|password|faceURL|picURL|picture|photo|base64|macAddress|ipAddress';

        $text = preg_replace(
            '/("(?:'.$keys.')"\s*:\s*)(?:"(?:[^"\\\\]|\\\\.)*"|-?\d+)/i',
            '$1"[redacted]"',
            $text,
        ) ?? '[withheld]';

        return $this->stripQuery($text);
    }

    private function stripQuery(string $text): string
    {
        return preg_replace('/(https?:\/\/[^\s"\'?#\\\\]+)\?[^\s"\'#\\\\]*/i', '$1?[query removed]', $text) ?? $text;
    }

    private function placeholder(string $kind, string $value): string
    {
        $this->seen[$kind] ??= [];
        $this->seen[$kind][$value] ??= count($this->seen[$kind]) + 1;

        return $kind.'-'.$this->seen[$kind][$value];
    }
}
