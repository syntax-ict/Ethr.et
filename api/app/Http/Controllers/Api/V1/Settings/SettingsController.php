<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SettingsController extends Controller
{
    public function index(): JsonResponse
    {
        $tenant = app(CurrentTenant::class)->get();

        return response()->json([
            'organization' => [
                'name' => $tenant->name,
                'subdomain' => $tenant->subdomain,
                'type' => $tenant->type,
                'timezone' => $tenant->settings['timezone'] ?? 'Africa/Addis_Ababa',
                'locale' => $tenant->settings['locale'] ?? 'en',
            ],
            'attendance' => [
                'grace_period_minutes' => $tenant->settings['grace_period_minutes'] ?? 15,
                'ot_daily_cap_minutes' => $tenant->settings['ot_daily_cap_minutes'] ?? 120,
                'confidence_threshold' => $tenant->settings['confidence_threshold'] ?? 70,
            ],
            'leave' => [
                'working_days' => $tenant->settings['working_days'] ?? [1, 2, 3, 4, 5],
            ],
            'payroll' => [
                'pay_period' => $tenant->settings['pay_period'] ?? 'monthly',
                'run_day' => $tenant->settings['run_day'] ?? 25,
            ],
            'security' => [
                'mfa_policy' => $tenant->settings['mfa_policy'] ?? 'optional',
                'session_timeout_minutes' => $tenant->settings['session_timeout_minutes'] ?? 480,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        Gate::authorize('settings.manage');

        $request->validate([
            'settings' => ['required', 'array'],
        ]);

        $tenant = app(CurrentTenant::class)->get();
        $currentSettings = $tenant->settings ?? [];
        $newSettings = array_merge($currentSettings, $request->input('settings'));
        $tenant->update(['settings' => $newSettings]);

        AuditLog::record('settings.updated', $tenant);

        return response()->json(['message' => 'Settings updated', 'settings' => $newSettings]);
    }
}
