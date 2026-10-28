<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Contracts\SmsSender;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveSmsGatewayRequest;
use App\Http\Requests\Admin\TestSmsGatewayRequest;
use App\Http\Requests\Admin\UpdateSmsSettingsRequest;
use App\Models\AuditLog;
use App\Models\SmsGateway;
use App\Models\SmsSend;
use App\Models\SmsSetting;
use App\Services\Sms\ConfiguredSmsSender;
use App\Services\Sms\HttpSmsGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * SMS delivery, configured by the super admin in the platform console: up to
 * two HTTP gateways, a route for one network, the on/off switch and a daily
 * cap. Behind `admin.manage` on the platform host with MFA, like every other
 * console endpoint.
 *
 * The switch turns on only while the primary gateway is verified — it has
 * delivered a test message — and editing how a gateway connects clears that,
 * so SMS can never be on with a configuration nobody has proven.
 *
 * No response carries a credential: `secret_set` says whether one is stored,
 * and the field is write-only. Audit entries name what changed, never values.
 */
class SmsDeliveryController extends Controller
{
    public function show(SmsSender $sender): JsonResponse
    {
        Gate::authorize('admin.manage');

        return response()->json($this->state($sender));
    }

    public function updateSettings(UpdateSmsSettingsRequest $request, SmsSender $sender): JsonResponse
    {
        Gate::authorize('admin.manage');

        $settings = SmsSetting::current();
        $settings->fill($request->validated());

        if ($settings->enabled && ! $this->primaryVerified()) {
            throw ValidationException::withMessages(['enabled' => __('sms.enable_requires_verified')]);
        }

        $changed = array_keys($settings->getDirty());
        $settings->save();

        if ($changed !== []) {
            AuditLog::record('platform.sms_settings_updated', $settings, ['changed' => $changed]);
        }

        return response()->json($this->state($sender));
    }

    public function saveGateway(SaveSmsGatewayRequest $request, string $slot, SmsSender $sender): JsonResponse
    {
        Gate::authorize('admin.manage');

        $data = $request->validated();
        $secretReplaced = filled($data['auth_secret'] ?? null);

        if (! $secretReplaced) {
            unset($data['auth_secret']);
        }

        $gateway = SmsGateway::query()->firstOrNew(['slot' => $slot]);
        $gateway->fill($data);

        if ($gateway->auth_type === 'none') {
            $gateway->auth_secret = null;
        }

        $changed = array_keys($gateway->getDirty());
        $reconnected = array_intersect($changed, SmsGateway::CONNECTION_FIELDS) !== [];

        if ($reconnected) {
            $gateway->verified_at = null;
        }

        $gateway->save();

        $disabled = $reconnected && $slot === SmsGateway::SLOT_PRIMARY && $this->switchOff();

        AuditLog::record('platform.sms_settings_updated', $gateway, [
            'slot' => $slot,
            'changed' => array_values(array_diff($changed, ['auth_secret'])),
            'secret_replaced' => $secretReplaced,
            'verification_cleared' => $reconnected,
            'sms_switched_off' => $disabled,
        ]);

        return response()->json($this->state($sender));
    }

    public function destroyGateway(string $slot, SmsSender $sender): JsonResponse
    {
        Gate::authorize('admin.manage');

        $gateway = SmsGateway::query()->where('slot', $slot)->firstOrFail();
        $gateway->delete();

        $disabled = $slot === SmsGateway::SLOT_PRIMARY && $this->switchOff();

        AuditLog::record('platform.sms_settings_updated', null, [
            'slot' => $slot,
            'removed' => true,
            'sms_switched_off' => $disabled,
        ]);

        return response()->json($this->state($sender));
    }

    public function testGateway(TestSmsGatewayRequest $request, string $slot): JsonResponse
    {
        Gate::authorize('admin.manage');

        $gateway = SmsGateway::query()->where('slot', $slot)->firstOrFail();
        $result = (new HttpSmsGateway($gateway))->send((string) $request->input('phone'), __('sms.test_message'));

        $gateway->forceFill([
            'last_test_at' => now(),
            'last_test_ok' => $result->ok,
            'last_test_status' => $result->status,
            'last_test_response' => $result->response,
            'verified_at' => $result->ok ? now() : $gateway->verified_at,
        ])->save();

        if ($result->ok) {
            SmsSend::create(['slot' => $slot, 'purpose' => SmsSend::PURPOSE_TEST]);
        }

        AuditLog::record('platform.sms_gateway_tested', $gateway, [
            'slot' => $slot,
            'ok' => $result->ok,
            'status' => $result->status,
        ]);

        return response()->json([
            'ok' => $result->ok,
            'status' => $result->status,
            'response' => $result->response,
            'verified' => $gateway->isVerified(),
        ]);
    }

    private function primaryVerified(): bool
    {
        $primary = SmsGateway::query()->where('slot', SmsGateway::SLOT_PRIMARY)->first();

        return $primary instanceof SmsGateway && $primary->isVerified();
    }

    /** Turns the switch off; true when it was on. */
    private function switchOff(): bool
    {
        $settings = SmsSetting::current();

        if (! $settings->enabled) {
            return false;
        }

        $settings->update(['enabled' => false]);

        return true;
    }

    /** @return array<string, mixed> */
    private function state(SmsSender $sender): array
    {
        $settings = SmsSetting::current();
        $gateways = SmsGateway::query()->get()->keyBy('slot');
        $primary = $gateways->get(SmsGateway::SLOT_PRIMARY);
        $secondary = $gateways->get(SmsGateway::SLOT_SECONDARY);

        $source = $primary instanceof SmsGateway
            ? 'console'
            : (ConfiguredSmsSender::gatewayFromEnv() !== null ? 'env' : 'none');

        return [
            'enabled' => $settings->enabled,
            'route_prefix' => $settings->route_prefix,
            'daily_cap' => $settings->daily_cap,
            'sent_today' => SmsSend::countToday(),
            'invitations_enabled' => $settings->invitations_enabled,
            'invitation_share' => $settings->invitation_share,
            'invitations_sent_today' => SmsSend::countToday(SmsSend::PURPOSE_INVITATION),
            'available' => $sender->isAvailable(),
            'source' => $source,
            'primary' => $primary instanceof SmsGateway ? $this->gateway($primary) : null,
            'secondary' => $secondary instanceof SmsGateway ? $this->gateway($secondary) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function gateway(SmsGateway $gateway): array
    {
        return [
            'slot' => $gateway->slot,
            'label' => $gateway->label,
            'endpoint' => $gateway->endpoint,
            'method' => $gateway->method,
            'auth_type' => $gateway->auth_type,
            'auth_header' => $gateway->auth_header,
            'auth_username' => $gateway->auth_username,
            'secret_set' => $gateway->hasSecret(),
            'username_field' => $gateway->username_field,
            'password_field' => $gateway->password_field,
            'recipient_field' => $gateway->recipient_field,
            'message_field' => $gateway->message_field,
            'sender_field' => $gateway->sender_field,
            'sender_id' => $gateway->sender_id,
            'number_format' => $gateway->number_format,
            'success_json_path' => $gateway->success_json_path,
            'success_json_value' => $gateway->success_json_value,
            'success_body_contains' => $gateway->success_body_contains,
            'timeout_seconds' => $gateway->timeout_seconds,
            'verified' => $gateway->isVerified(),
            'verified_at' => $gateway->verified_at?->toIso8601String(),
            'last_test_at' => $gateway->last_test_at?->toIso8601String(),
            'last_test_ok' => $gateway->last_test_ok,
            'last_test_status' => $gateway->last_test_status,
            'last_test_response' => $gateway->last_test_response,
        ];
    }
}
