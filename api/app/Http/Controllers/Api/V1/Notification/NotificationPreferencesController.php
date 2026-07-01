<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Notification;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\NotificationPreference;
use App\Services\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-user × per-notification-type × per-channel opt-in/out matrix.
 *
 * Default (no row present) = enabled for in_app, email; disabled for sms.
 * The controller merges stored preferences with defaults so the client
 * always receives a complete matrix.
 */
class NotificationPreferencesController extends Controller
{
    public const NOTIFICATION_TYPES = [
        'leave_requested',
        'leave_approved',
        'leave_rejected',
        'attendance_correction',
        'attendance_anomaly',
        'payslip_available',
        'payroll_processed',
        'announcement',
        'approval_reminder',
    ];

    public const CHANNELS = ['in_app', 'email', 'sms'];

    private const DEFAULT_SMS_ENABLED = false;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $stored = NotificationPreference::where('user_id', $user->id)
            ->get()
            ->keyBy(fn ($p) => "{$p->notification_type}.{$p->channel}");

        $preferences = [];
        foreach (self::NOTIFICATION_TYPES as $type) {
            $preferences[$type] = [];
            foreach (self::CHANNELS as $channel) {
                $key = "{$type}.{$channel}";
                if ($stored->has($key)) {
                    $preferences[$type][$channel] = (bool) $stored[$key]->enabled;
                } else {
                    // Defaults: in-app always on, email on, SMS off
                    $preferences[$type][$channel] = $channel === 'sms'
                        ? self::DEFAULT_SMS_ENABLED
                        : true;
                }
            }
        }

        return response()->json([
            'notification_types' => self::NOTIFICATION_TYPES,
            'channels' => self::CHANNELS,
            'preferences' => $preferences,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*' => ['array'],
            'preferences.*.*' => ['boolean'],
        ]);

        $user = $request->user();
        $tenant = app(CurrentTenant::class)->get();

        foreach ($validated['preferences'] as $type => $channels) {
            if (! in_array($type, self::NOTIFICATION_TYPES, true)) {
                continue;
            }

            foreach ($channels as $channel => $enabled) {
                if (! in_array($channel, self::CHANNELS, true)) {
                    continue;
                }

                // in_app can't be disabled — safety rail
                $enforcedEnabled = $channel === 'in_app' ? true : (bool) $enabled;

                NotificationPreference::updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'notification_type' => $type,
                        'channel' => $channel,
                    ],
                    [
                        'tenant_id' => $tenant->id,
                        'enabled' => $enforcedEnabled,
                    ]
                );
            }
        }

        AuditLog::record('user.notification_preferences_updated', $user);

        return $this->index($request);
    }
}
