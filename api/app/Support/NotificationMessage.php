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
     * @param  array<string, scalar|null>  $params
     * @return array{message: string, message_key: string, message_params: array<string, scalar|null>}
     */
    public static function make(string $key, array $params = []): array
    {
        return [
            'message' => (string) __($key, $params),
            'message_key' => $key,
            'message_params' => $params,
        ];
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function localise(array $data): array
    {
        $key = $data['message_key'] ?? null;
        $params = $data['message_params'] ?? [];

        if (! is_string($key) || ! is_array($params) || ! Lang::has($key)) {
            return $data;
        }

        $replace = array_filter($params, fn (mixed $value, mixed $name): bool => is_string($name) && (is_scalar($value) || $value === null), ARRAY_FILTER_USE_BOTH);
        $message = __($key, $replace);

        if (is_string($message)) {
            $data['message'] = $message;
        }

        return $data;
    }
}
