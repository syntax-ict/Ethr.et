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
 */
final class NotificationMessage
{
    /**
     * `$labels` names the parameters that are themselves translations: each
     * maps to its key, read again in the reader's language (a request type, a
     * voice status). The parameter keeps the code, which stands in when the
     * key has no translation. `$count` picks the plural form, as
     * `trans_choice` does. Both are stored only when given, so a plain
     * message keeps exactly the shape it had.
     *
     * @param  array<string, scalar|null>  $params
     * @param  array<string, string>  $labels
     * @return array{message: string, message_key: string, message_params: array<string, scalar|null>, message_labels?: array<string, string>, message_count?: int}
     */
    public static function make(string $key, array $params = [], array $labels = [], ?int $count = null): array
    {
        $data = [
            'message' => self::render($key, $params, $labels, $count) ?? '',
            'message_key' => $key,
            'message_params' => $params,
        ];

        if ($labels !== []) {
            $data['message_labels'] = $labels;
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
    public static function localise(array $data): array
    {
        $key = $data['message_key'] ?? null;
        $params = $data['message_params'] ?? [];
        $labels = $data['message_labels'] ?? [];
        $count = $data['message_count'] ?? null;

        if (! is_string($key) || ! is_array($params) || ! is_array($labels) || ! ($count === null || is_int($count)) || ! Lang::has($key)) {
            return $data;
        }

        $replace = array_filter($params, fn (mixed $value, mixed $name): bool => is_string($name) && (is_scalar($value) || $value === null), ARRAY_FILTER_USE_BOTH);
        $labelKeys = array_filter($labels, fn (mixed $value, mixed $name): bool => is_string($name) && is_string($value), ARRAY_FILTER_USE_BOTH);

        $message = self::render($key, $replace, $labelKeys, $count);

        if ($message !== null) {
            $data['message'] = $message;
        }

        return $data;
    }

    /**
     * @param  array<array-key, scalar|null>  $params
     * @param  array<array-key, string>  $labels
     */
    private static function render(string $key, array $params, array $labels, ?int $count): ?string
    {
        foreach ($labels as $name => $labelKey) {
            $label = Lang::has($labelKey) ? __($labelKey) : null;
            $params[$name] = is_string($label) ? $label : ($params[$name] ?? '');
        }

        $message = $count === null ? __($key, $params) : trans_choice($key, $count, $params);

        return is_string($message) ? $message : null;
    }
}
