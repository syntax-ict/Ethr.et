<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfilePreferencesRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\CalendarPreference;
use Illuminate\Http\JsonResponse;

/**
 * Display preferences for the signed-in user.
 *
 * Language lives on `users.locale` rather than only in the browser because the
 * server renders payslips, emails and SMS in the employee's language too — a
 * localStorage-only switcher leaves every one of those in English.
 *
 * The calendar read back is the one in force, not only the one stored: a user
 * who has not chosen inherits their organisation's (`CalendarPreference`). It
 * used to fall back to "gregorian" while the app displayed Ethiopian (N33).
 */
class ProfilePreferencesController extends Controller
{
    /**
     * `punch_reminders` and `auto_check_in` are the employee's own opt-ins to
     * the punching assistant; each also needs the organisation's switch
     * (AttendanceSetting). Reminders are on unless turned off; checking in
     * for someone is never assumed.
     *
     * @var array{theme: string, calendar: string, punch_reminders: bool, auto_check_in: bool}
     */
    private const DEFAULTS = [
        'theme' => 'system',
        'calendar' => CalendarPreference::DEFAULT,
        'punch_reminders' => true,
        'auto_check_in' => false,
    ];

    public function update(UpdateProfilePreferencesRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = array_filter($request->validated(), fn ($v) => $v !== null);

        $changes = [];

        if (isset($data['locale'])) {
            $changes['locale'] = $data['locale'];
        }

        // `users.locale` defaults to "en" and several sign-up paths write "en",
        // so the column alone cannot say whether anyone chose it. The marker
        // does: only a chosen language follows the user to a new device.
        $display = array_intersect_key($data, self::DEFAULTS);
        if (isset($data['locale'])) {
            $display['locale_chosen'] = true;
        }
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
     * @return array{locale: string, locale_chosen: bool, theme: string, calendar: string, punch_reminders: bool, auto_check_in: bool}
     */
    public static function present(User $user): array
    {
        $stored = is_array($user->preferences) ? $user->preferences : [];

        return [
            'locale' => $user->locale ?? 'en',
            'locale_chosen' => ($stored['locale_chosen'] ?? false) === true,
            'theme' => is_string($stored['theme'] ?? null) ? $stored['theme'] : self::DEFAULTS['theme'],
            'calendar' => CalendarPreference::forUser($user),
            'punch_reminders' => (bool) ($stored['punch_reminders'] ?? self::DEFAULTS['punch_reminders']),
            'auto_check_in' => (bool) ($stored['auto_check_in'] ?? self::DEFAULTS['auto_check_in']),
        ];
    }
}
