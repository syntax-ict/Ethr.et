<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Lang;

/**
 * The in-app text of a database notification, readable in the language the
 * reader is using now. `make()` stores the sentence as translated when it was
 * sent (in the recipient's saved `users.locale`), plus its key and
 * parameters; `localise()` translates it again when it is read, in the locale
 * of that request. The header's language switch changes only the browser's
 * locale, so a reader whose saved locale is `en` saw English notices in an
 * Amharic dashboard (production QA 2026-10-28, F-02).
 *
 * `message` stays in the row: rows stored before the key was, and readers
 * that do not go through the API (web push), still use it.
 *
 * Dates and times are stored as values, not text (`message_dates` names
 * them), and written for whoever reads them: `make()` for the recipient, in
 * the push and the stored `message`; `localise()` for the reader of the
 * request, in their calendar, clock and language (`ReaderDates`).
 */
final class NotificationMessage
{
    /** What a parameter named in `$dates` can be. */
    public const DATE_KINDS = ['date', 'time', 'time_of_day', 'datetime', 'period'];

    /**
     * `$labels` names the parameters that are themselves translations: each
     * maps to its key, read again in the reader's language (a request type, a
     * voice status). The parameter keeps the code, which stands in when the
     * key has no translation. `$count` picks the plural form, as
     * `trans_choice` does. `$dates` names the parameters that are dates or
     * times, and what each is (`DATE_KINDS`); their values are `Y-m-d` dates,
     * ISO instants, `H:i` times, or `Y-m-d/Y-m-d` for a pay period. Each is
     * stored only when given, so a plain message keeps exactly the shape it
     * had.
     *
     * @param  array<string, scalar|null>  $params
     * @param  array<string, string>  $labels
     * @param  array<string, string>  $dates
     * @return array{message: string, message_key: string, message_params: array<string, scalar|null>, message_labels?: array<string, string>, message_count?: int, message_dates?: array<string, string>}
     */
    public static function make(string $key, array $params = [], array $labels = [], ?int $count = null, array $dates = [], ?ReaderDates $reader = null): array
    {
        $data = [
            'message' => self::render($key, $params, $labels, $count, $dates, $reader) ?? '',
            'message_key' => $key,
            'message_params' => $params,
        ];

        if ($labels !== []) {
            $data['message_labels'] = $labels;
        }

        if ($dates !== []) {
            $data['message_dates'] = $dates;
        }

        if ($count !== null) {
            $data['message_count'] = $count;
        }

        return $data;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function localise(array $data, ?ReaderDates $reader = null): array
    {
        $key = $data['message_key'] ?? null;
        $params = $data['message_params'] ?? [];
        $labels = $data['message_labels'] ?? [];
        $count = $data['message_count'] ?? null;
        $dates = $data['message_dates'] ?? [];

        if (! is_string($key) || ! is_array($params) || ! is_array($labels) || ! is_array($dates) || ! ($count === null || is_int($count)) || ! Lang::has($key)) {
            return $data;
        }

        $replace = array_filter($params, fn (mixed $value, mixed $name): bool => is_string($name) && (is_scalar($value) || $value === null), ARRAY_FILTER_USE_BOTH);
        $labelKeys = array_filter($labels, fn (mixed $value, mixed $name): bool => is_string($name) && is_string($value), ARRAY_FILTER_USE_BOTH);

        $dateKinds = array_filter($dates, fn (mixed $value, mixed $name): bool => is_string($name) && in_array($value, self::DATE_KINDS, true), ARRAY_FILTER_USE_BOTH);

        $message = self::render($key, $replace, $labelKeys, $count, $dateKinds, $reader);

        if ($message !== null) {
            $data['message'] = $message;
        }

        return $data;
    }

    /**
     * @param  array<array-key, scalar|null>  $params
     * @param  array<array-key, string>  $labels
     * @param  array<array-key, string>  $dates
     */
    private static function render(string $key, array $params, array $labels, ?int $count, array $dates = [], ?ReaderDates $reader = null): ?string
    {
        $reader ??= ReaderDates::for(null);

        foreach ($dates as $name => $kind) {
            $value = $params[$name] ?? null;
            if (! is_string($value) || $value === '') {
                continue;
            }

            $params[$name] = match ($kind) {
                'date' => $reader->date($value),
                'time' => $reader->time($value),
                'time_of_day' => $reader->timeOfDay($value),
                'datetime' => $reader->dateTime($value),
                'period' => preg_match('#^\d{4}-\d{2}-\d{2}/\d{4}-\d{2}-\d{2}$#', $value) === 1
                    ? $reader->period(...explode('/', $value, 2))
                    : $value,
                default => $value,
            };
        }

        foreach ($labels as $name => $labelKey) {
            $label = Lang::has($labelKey) ? __($labelKey) : null;
            $params[$name] = is_string($label) ? $label : ($params[$name] ?? '');
        }

        $message = $count === null ? __($key, $params) : trans_choice($key, $count, $params);

        return is_string($message) ? $message : null;
    }
}
