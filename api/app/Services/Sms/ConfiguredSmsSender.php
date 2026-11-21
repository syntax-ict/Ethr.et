<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Contracts\SmsSender;
use App\Models\AuditLog;
use App\Models\SmsGateway;
use App\Models\SmsSend;
use App\Models\SmsSetting;
use App\Notifications\SmsGatewayPausedNotification;
use App\Services\Support\PlatformSupportInbox;
use App\Support\EthiopianPhone;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * The platform's SMS sender: the gateways the super admin configured in the
 * console, else the `.env` gateway, else nothing.
 *
 * Precedence, so a deployment that already set `.env` keeps working:
 *
 * 1. **Console.** Once a primary gateway exists in `sms_gateways`, the console
 *    decides alone. SMS is available only while the switch is on, the primary
 *    gateway has passed a test send, today's count is under the cap, and the
 *    primary is not paused. A gateway that refuses `PAUSE_AFTER_FAILURES` real
 *    messages in a row is paused for `PAUSE_MINUTES`: SMS reports itself
 *    unavailable (the sign-in page offers email alone), the super admins are
 *    told once, and the first send after the cooldown is the probe.
 * 2. **`.env`.** With no console gateway, `SMS_DRIVER=ethiotelecom` and the
 *    `ETHIOTELECOM_SMS_*` values describe one form-post gateway — the shape the
 *    old EthioTelecomSmsSender guessed at, now expressed as configuration. It
 *    is available once endpoint, username and password are set, as before.
 * 3. **Nothing.** Development logs the message (LogSmsSender) and SMS reports
 *    itself unavailable, so the sign-in page offers email codes alone.
 *
 * State is read on every call rather than at boot, so a change in the console
 * takes effect on the next request without a deploy or a cache clear.
 */
final class ConfiguredSmsSender implements SmsSender
{
    public function __construct(private readonly LogSmsSender $log) {}

    public function isAvailable(): bool
    {
        $plan = $this->plan();

        return $plan !== null && $plan['cap'] > SmsSend::countToday();
    }

    public function send(string $to, string $message): bool
    {
        return $this->deliver($to, $message, SmsSend::PURPOSE_MESSAGE);
    }

    /**
     * Whether invitations go by text at all: SMS works and the platform has
     * switched text invitations on (console, `sms_settings.invitations_enabled`).
     * Off, a person with only a phone is activated in person.
     */
    public function invitationsEnabled(): bool
    {
        return $this->plan() !== null && SmsSetting::current()->invitations_enabled;
    }

    /**
     * Whether one more invitation fits today: inside the invitations' share of
     * the daily cap, so sign-in codes keep the rest for every organisation.
     */
    public function invitationBudgetLeft(): bool
    {
        $plan = $this->plan();
        if ($plan === null) {
            return false;
        }

        $share = max(0, min(100, SmsSetting::current()->invitation_share));

        return SmsSend::countToday(SmsSend::PURPOSE_INVITATION) < intdiv($plan['cap'] * $share, 100);
    }

    /** An invitation, counted against the invitations' share. False when it does not fit or was not accepted. */
    public function sendInvitation(string $to, string $message): bool
    {
        if (! $this->invitationsEnabled() || ! $this->invitationBudgetLeft()) {
            return false;
        }

        return $this->deliver($to, $message, SmsSend::PURPOSE_INVITATION);
    }

    private function deliver(string $to, string $message, string $purpose): bool
    {
        $plan = $this->plan();

        if ($plan === null) {
            if (config('sms.driver') === 'log') {
                $this->log->send($to, $message);
            }

            return false;
        }

        if (! EthiopianPhone::isMobile($to)) {
            Log::warning('SMS not sent: recipient is not an Ethiopian mobile number.');

            return false;
        }

        if (SmsSend::countToday() >= $plan['cap']) {
            Log::warning('SMS not sent: the platform daily cap is reached.', ['cap' => $plan['cap']]);

            return false;
        }

        foreach ($this->gatewaysFor($to, $plan) as $gateway) {
            $http = new HttpSmsGateway($gateway);
            $result = $http->send($to, $message);

            if ($result->ok) {
                SmsSend::create(['slot' => $gateway->slot, 'purpose' => $purpose]);
                $this->recordSuccess($gateway);

                return true;
            }

            // The number is personal data: the response is stored and logged
            // only with it taken out.
            $response = $http->withoutRecipient($result->response, $to);

            Log::warning('SMS gateway did not accept the message', [
                'slot' => $gateway->slot,
                'status' => $result->status,
                'response' => Str::limit($response, 300),
            ]);

            $this->recordFailure($gateway, $result->status, $response);
        }

        return false;
    }

    /** A real message went through: the refusals before it prove nothing now. The `.env` gateway has no row to record on. */
    private function recordSuccess(SmsGateway $gateway): void
    {
        if (! $gateway->exists) {
            return;
        }

        $gateway->forceFill([
            'consecutive_failures' => 0,
            'last_success_at' => now(),
            'paused_until' => null,
        ])->save();
    }

    /**
     * A real message was refused. The count moves in one statement so two
     * sends refused together cannot both read the same number; the send that
     * takes it to the threshold pauses the gateway and tells the super admins,
     * and a refusal after that (the probe once the cooldown is over) only
     * pauses it again.
     */
    private function recordFailure(SmsGateway $gateway, ?int $status, string $response): void
    {
        if (! $gateway->exists) {
            return;
        }

        SmsGateway::query()->whereKey($gateway->getKey())->increment('consecutive_failures', 1, [
            'last_failure_at' => now(),
            'last_failure_status' => $status,
            'last_failure_response' => $response,
        ]);

        $failures = (int) SmsGateway::query()->whereKey($gateway->getKey())->value('consecutive_failures');

        if ($failures < SmsGateway::PAUSE_AFTER_FAILURES) {
            return;
        }

        $until = now()->addMinutes(SmsGateway::PAUSE_MINUTES);
        SmsGateway::query()->whereKey($gateway->getKey())->update(['paused_until' => $until]);

        if ($failures === SmsGateway::PAUSE_AFTER_FAILURES) {
            $this->announcePause($gateway, $failures, $status, $response, $until->toIso8601String());
        }
    }

    /** Never allowed to break the send that triggered it. */
    private function announcePause(SmsGateway $gateway, int $failures, ?int $status, string $response, string $until): void
    {
        try {
            AuditLog::record('platform.sms_gateway_paused', $gateway, [
                'slot' => $gateway->slot,
                'failures' => $failures,
                'status' => $status,
            ]);

            Notification::send(
                app(PlatformSupportInbox::class)->platformAdmins(),
                new SmsGatewayPausedNotification($gateway->slot, $gateway->label, $failures, $status, $response, $until),
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The gateways that may carry this number, in the order to try them: the
     * routed gateway first when the number is on the routed network, then the
     * primary as its fallback.
     *
     * @param  array{primary: SmsGateway, secondary: ?SmsGateway, route: ?string, cap: int}  $plan
     * @return list<SmsGateway>
     */
    private function gatewaysFor(string $to, array $plan): array
    {
        $network = substr((string) EthiopianPhone::canonical($to), 4, 1);

        if ($plan['secondary'] !== null && $plan['route'] !== null && $network === $plan['route']) {
            return [$plan['secondary'], $plan['primary']];
        }

        return [$plan['primary']];
    }

    /**
     * What may send right now, or null when SMS is off.
     *
     * @return array{primary: SmsGateway, secondary: ?SmsGateway, route: ?string, cap: int}|null
     */
    private function plan(): ?array
    {
        $gateways = SmsGateway::query()->get()->keyBy('slot');
        $primary = $gateways->get(SmsGateway::SLOT_PRIMARY);

        if ($primary instanceof SmsGateway) {
            $settings = SmsSetting::current();

            if (! $settings->enabled || ! $primary->isVerified() || $primary->isPaused()) {
                return null;
            }

            $secondary = $gateways->get(SmsGateway::SLOT_SECONDARY);

            return [
                'primary' => $primary,
                // A route only counts once its own gateway has passed a test, and drops out while it is paused.
                'secondary' => $secondary instanceof SmsGateway && $secondary->isVerified() && ! $secondary->isPaused() ? $secondary : null,
                'route' => $settings->route_prefix,
                'cap' => $settings->daily_cap,
            ];
        }

        $fromEnv = self::gatewayFromEnv();

        return $fromEnv === null ? null : [
            'primary' => $fromEnv,
            'secondary' => null,
            'route' => null,
            'cap' => (int) config('sms.daily_cap', 500),
        ];
    }

    /**
     * The `.env` gateway as configuration, never saved: a form post of
     * username, password, from, to and text, the number as 2519…, success on
     * any 2xx. Null unless SMS_DRIVER=ethiotelecom and the endpoint and
     * credentials are set.
     */
    public static function gatewayFromEnv(): ?SmsGateway
    {
        $env = (array) config('sms.ethiotelecom', []);

        if (config('sms.driver') !== 'ethiotelecom'
            || blank($env['endpoint'] ?? null)
            || blank($env['username'] ?? null)
            || blank($env['password'] ?? null)) {
            return null;
        }

        return new SmsGateway([
            'slot' => SmsGateway::SLOT_PRIMARY,
            'endpoint' => (string) $env['endpoint'],
            'method' => 'form',
            'auth_type' => 'fields',
            'auth_username' => (string) $env['username'],
            'auth_secret' => (string) $env['password'],
            'username_field' => 'username',
            'password_field' => 'password',
            'recipient_field' => 'to',
            'message_field' => 'text',
            'sender_field' => 'from',
            'sender_id' => (string) ($env['sender_id'] ?? 'ETHR'),
            'number_format' => 'international',
            'timeout_seconds' => (int) ($env['timeout'] ?? 10),
        ]);
    }
}
