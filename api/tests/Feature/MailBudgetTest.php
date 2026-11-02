<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Notifications\AccountActivationNotification;
use App\Notifications\ApprovalReminderNotification;
use App\Notifications\AttendanceAutoCheckedOutNotification;
use App\Notifications\AttendancePhotosDueForDeletionNotification;
use App\Notifications\Contracts\BulkMail;
use App\Notifications\Contracts\EssentialMail;
use App\Notifications\DashboardDigestNotification;
use App\Notifications\ExpiryReminderNotification;
use App\Notifications\MfaResetNotification;
use App\Notifications\MissingPunchNotification;
use App\Notifications\OrganisationSignInLinksNotification;
use App\Notifications\PasswordResetLinkNotification;
use App\Notifications\PayslipAvailableNotification;
use App\Notifications\SignInCodeNotification;
use App\Support\Mail\MailBudget;
use App\Support\Mail\MailHealth;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;

/**
 * The day's mail against the mailbox's send cap: bulk mail stops at its share,
 * other mail at 90%, essential mail never, and a held email leaves the in-app
 * notice in place.
 */
beforeEach(function () {
    Cache::flush();
    config(['mail.default' => 'array', 'mail.daily_cap' => 10, 'mail.bulk_share' => 50]);
});

/** Mail that has left through the array mailer in this test. */
function mailSentCount(): int
{
    return count(app('mailer')->getSymfonyTransport()->messages());
}

function sentToday(int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        MailBudget::recordSent();
    }
}

/** A notification of the given kind with both an in-app and a mail copy. */
function budgetNotice(string $kind): Notification
{
    return match ($kind) {
        'bulk' => new class extends Notification implements BulkMail
        {
            public function via(object $notifiable): array
            {
                return ['database', 'mail'];
            }

            public function toMail(object $notifiable): MailMessage
            {
                return (new MailMessage)->subject('Bulk')->line('Bulk');
            }

            public function toArray(object $notifiable): array
            {
                return ['message' => 'Bulk'];
            }
        },
        'essential' => new class extends Notification implements EssentialMail
        {
            public function via(object $notifiable): array
            {
                return ['mail'];
            }

            public function toMail(object $notifiable): MailMessage
            {
                return (new MailMessage)->subject('Essential')->line('Essential');
            }
        },
        default => new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return ['mail'];
            }

            public function toMail(object $notifiable): MailMessage
            {
                return (new MailMessage)->subject('Other')->line('Other');
            }
        },
    };
}

it('holds bulk email at its share of the cap and still delivers the in-app notice', function () {
    $user = createUser();

    sentToday(4);
    $user->notifyNow(budgetNotice('bulk'));
    expect(mailSentCount())->toBe(1);

    sentToday(1);
    $user->notifyNow(budgetNotice('bulk'));

    expect(mailSentCount())->toBe(1)
        ->and(MailBudget::heldToday())->toBe(1)
        ->and($user->notifications()->count())->toBe(2);
});

it('holds other email at 90% of the cap', function () {
    $user = createUser();

    sentToday(8);
    $user->notifyNow(budgetNotice('other'));
    expect(mailSentCount())->toBe(1);

    sentToday(1);
    $user->notifyNow(budgetNotice('other'));
    expect(mailSentCount())->toBe(1);
});

it('never holds essential email, even past the cap', function () {
    $user = createUser();
    sentToday(25);

    $user->notifyNow(budgetNotice('essential'));

    expect(mailSentCount())->toBe(1)
        ->and(MailBudget::heldToday())->toBe(0);
});

it('holds nothing when no cap is set', function () {
    config(['mail.daily_cap' => 0]);
    $user = createUser();
    sentToday(1000);

    $user->notifyNow(budgetNotice('bulk'));

    expect(mailSentCount())->toBe(1);
});

it('starts a new day at midnight in Addis Ababa', function () {
    $this->travelTo(now('Africa/Addis_Ababa')->setTime(23, 30));
    sentToday(10);
    expect(MailBudget::sentToday())->toBe(10);

    $this->travel(1)->hours();
    expect(MailBudget::sentToday())->toBe(0);
});

it('counts mail that really left, from the transport record', function () {
    MailHealth::recordSent('verified');
    MailHealth::recordSent('pinned');

    expect(MailBudget::sentToday())->toBe(2);
});

it('shows the day on the operations centre, amber once mail is held', function () {
    config(['mail.default' => 'smtp']);
    MailHealth::recordSent('verified');
    sentToday(8);

    $tenant = createTenant();
    $this->actingAs(createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant));
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/admin/operations";

    $delivery = $this->getJson($url)->assertOk()->json('delivery');
    expect($delivery['state'])->toBe('warning')
        ->and($delivery['reasons'])->toBe(['mail_cap_near'])
        ->and($delivery['mail'])->toMatchArray(['sent_today' => 9, 'held_today' => 0, 'daily_cap' => 10]);

    MailBudget::recordHeld();
    $delivery = $this->getJson($url)->assertOk()->json('delivery');
    expect($delivery['state'])->toBe('warning')
        ->and($delivery['reasons'])->toBe(['mail_held'])
        ->and($delivery['mail']['held_today'])->toBe(1);
});

it('marks the notifications that fan out as bulk and the sign-in ones as essential', function () {
    foreach ([
        PayslipAvailableNotification::class,
        MissingPunchNotification::class,
        AttendanceAutoCheckedOutNotification::class,
        ApprovalReminderNotification::class,
        DashboardDigestNotification::class,
        ExpiryReminderNotification::class,
        AttendancePhotosDueForDeletionNotification::class,
    ] as $class) {
        expect(is_subclass_of($class, BulkMail::class))->toBeTrue($class);
    }

    foreach ([
        AccountActivationNotification::class,
        PasswordResetLinkNotification::class,
        SignInCodeNotification::class,
        MfaResetNotification::class,
        OrganisationSignInLinksNotification::class,
    ] as $class) {
        expect(is_subclass_of($class, EssentialMail::class))->toBeTrue($class);
    }
});
