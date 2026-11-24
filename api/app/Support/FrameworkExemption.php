<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The note on an employee who is outside their organisation's employment
 * framework explains the flag, so it exists only while the flag is on.
 */
final class FrameworkExemption
{
    /**
     * @param  array<string, mixed>  $data  attributes about to be saved
     * @return array<string, mixed>
     */
    public static function normalise(array $data, bool $currentlyOutside): array
    {
        if (! array_key_exists('outside_employment_framework', $data) && ! array_key_exists('employment_framework_note', $data)) {
            return $data;
        }

        $outside = array_key_exists('outside_employment_framework', $data)
            ? (bool) $data['outside_employment_framework']
            : $currentlyOutside;

        if (! $outside) {
            $data['employment_framework_note'] = null;
        } elseif (is_string($data['employment_framework_note'] ?? null)) {
            $note = trim($data['employment_framework_note']);
            $data['employment_framework_note'] = $note === '' ? null : $note;
        }

        return $data;
    }
}
