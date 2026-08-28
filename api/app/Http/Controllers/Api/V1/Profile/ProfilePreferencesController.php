<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfilePreferencesRequest;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Display preferences for the signed-in user.
 *
 * Language lives on `users.locale` rather than only in the browser because the
 * server renders payslips, emails and SMS in the employee's language too — a
 * localStorage-only switcher leaves every one of those in English.
 */
class ProfilePreferencesController extends Controller
{
    /** @var array{theme: string, calendar: string} */
    private const DEFAULTS = [
        'theme' => 'system',
        'calendar' => 'gregorian',
    ];

    public function update(UpdateProfilePreferencesRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = array_filter($request->validated(), fn ($v) => $v !== null);

        $changes = [];

        if (isset($data['locale'])) {
            $changes['locale'] = $data['locale'];
        }

        $display = array_intersect_key($data, self::DEFAULTS);
        if ($display !== []) {
            // Merge, not replace: sending only `theme` must not drop the calendar.
            $changes['preferences'] = array_merge(
                is_array($user->preferences) ? $user->preferences : [],
                $display,
            );
        }

        if ($changes !== []) {
            $user->update($changes);
            AuditLog::record('profile.preferences_updated', $user, $changes);
        }

        return response()->json(self::present($user->refresh()));
    }

    /**
     * @return array{locale: string, theme: string, calendar: string}
     */
    public static function present(User $user): array
    {
        $stored = is_array($user->preferences) ? $user->preferences : [];

        return [
            'locale' => $user->locale ?? 'en',
            'theme' => is_string($stored['theme'] ?? null) ? $stored['theme'] : self::DEFAULTS['theme'],
            'calendar' => is_string($stored['calendar'] ?? null) ? $stored['calendar'] : self::DEFAULTS['calendar'],
        ];
    }
}
