<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\DashboardDigestFailedNotification;
use App\Notifications\LeaveApprovedNotification;
use App\Notifications\OrganisationSignInLinksNotification;
use App\Support\MailBrand;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Notification;

// Communication upgrade PR 5. Every email says which organisation it is from:
// the layout reads MailBrand, and BrandMail fills it from the recipient's own
// row just before the mail is built.

function brandedLeave(Tenant $tenant): LeaveRequest
{
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    return LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => LeaveType::factory()->create(['tenant_id' => $tenant->id])->id,
        'start_date' => '2026-11-02',
        'end_date' => '2026-11-04',
    ]);
}

/**
 * Dispatches the event the notification system fires, then renders the mail it
 * would send, in the recipient's language as NotificationSender does.
 */
function renderBranded(object $notifiable, Notification $notification): string
{
    $previous = app()->getLocale();
    app()->setLocale($notifiable instanceof User ? ($notifiable->preferredLocale() ?? 'en') : 'en');

    try {
        event(new NotificationSending($notifiable, $notification, 'mail'));

        return (string) $notification->toMail($notifiable)->render();
    } finally {
        app()->setLocale($previous);
    }
}

function brandedMemberOf(Tenant $tenant, array $settings = [], array $user = []): User
{
    $tenant->update(['settings' => [...($tenant->settings ?? []), ...$settings]]);

    return createUser(['role' => UserRole::EMPLOYEE, ...$user], $tenant);
}

it('prints the organisation\'s name and contact line, never Laravel', function () {
    $tenant = createTenant(['name' => 'Habru Woreda']);
    $reader = brandedMemberOf($tenant, ['letterhead_phone' => '+251 11 555 0100']);

    $html = renderBranded($reader, new LeaveApprovedNotification(brandedLeave($tenant)));

    expect($html)->toContain('Habru Woreda')
        ->toContain('Tel: +251 11 555 0100')
        ->toContain('Sent with ETHR')
        ->not->toContain('Laravel');
});

it('does not carry one organisation into the next email sent in the same process', function () {
    $first = createTenant(['name' => 'Alpha Bank']);
    $alphaReader = brandedMemberOf($first, ['letterhead_phone' => '+251 11 111 1111']);
    $second = createTenant(['name' => 'Beta Clinic']);
    $betaReader = brandedMemberOf($second);

    renderBranded($alphaReader, new LeaveApprovedNotification(brandedLeave($first)));
    $html = renderBranded($betaReader, new LeaveApprovedNotification(brandedLeave($second)));

    expect($html)->toContain('Beta Clinic')
        ->not->toContain('Alpha Bank')
        ->not->toContain('+251 11 111 1111');
});

it('prints ETHR, without the "Sent with ETHR" line, for an anonymous recipient sent right after an organisation', function () {
    $tenant = createTenant(['name' => 'Habru Woreda']);
    $reader = brandedMemberOf($tenant, ['letterhead_phone' => '0111']);
    renderBranded($reader, new LeaveApprovedNotification(brandedLeave($tenant)));

    $notification = new OrganisationSignInLinksNotification([['name' => 'Elsewhere', 'subdomain' => 'x']]);
    $html = renderBranded((new AnonymousNotifiable)->route('mail', 'someone@example.test'), $notification);

    expect(app(MailBrand::class)->isPlatform())->toBeTrue()
        ->and($html)->toContain('ETHR')
        ->not->toContain('Habru Woreda')
        ->not->toContain('0111')
        ->not->toContain('Sent with ETHR')
        ->not->toContain('Laravel');
});

it('prints ETHR for a platform account with no organisation, sent right after an organisation', function () {
    app()->forgetScopedInstances();
    $platformUser = User::factory()->create(['tenant_id' => null]);
    $tenant = createTenant(['name' => 'Habru Woreda']);
    $reader = brandedMemberOf($tenant, ['letterhead_phone' => '0111']);
    renderBranded($reader, new LeaveApprovedNotification(brandedLeave($tenant)));
    expect(app(MailBrand::class)->name())->toBe('Habru Woreda');

    $html = renderBranded($platformUser, new OrganisationSignInLinksNotification([['name' => 'Elsewhere', 'subdomain' => 'x']]));

    expect(app(MailBrand::class)->isPlatform())->toBeTrue()
        ->and($html)->not->toContain('Habru Woreda')
        ->not->toContain('0111')
        ->not->toContain('Sent with ETHR');
});

it('renders in every shipped language and uses that language for the fixed words', function (string $locale, string $sentWith) {
    $tenant = createTenant(['name' => 'Habru Woreda']);
    $reader = brandedMemberOf($tenant, [], ['locale' => $locale]);

    $html = renderBranded($reader, new LeaveApprovedNotification(brandedLeave($tenant)));

    expect($html)->toContain('Habru Woreda')->toContain($sentWith);
})->with([
    'English' => ['en', 'Sent with ETHR'],
    'Amharic' => ['am', 'በETHR የተላከ'],
    'Afaan Oromoo' => ['om', 'ETHR fayyadamuun ergame'],
]);

it('labels the contact lines in the recipient\'s language', function () {
    $tenant = createTenant(['name' => 'Habru Woreda']);
    $reader = brandedMemberOf($tenant, ['letterhead_phone' => '0111'], ['locale' => 'am']);

    $html = renderBranded($reader, new LeaveApprovedNotification(brandedLeave($tenant)));

    expect($html)->toContain('ስልክ: 0111');
});

it('escapes markup in a contact line and in the organisation name', function () {
    $tenant = createTenant(['name' => 'Tom <i>& Sons']);
    $reader = brandedMemberOf($tenant, ['letterhead_address' => '<script>alert(1)</script>']);

    $html = renderBranded($reader, new LeaveApprovedNotification(brandedLeave($tenant)));

    expect($html)->not->toContain('<script>')
        ->not->toContain('<i>& Sons')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->toContain('Tom &lt;i&gt;&amp; Sons');
});

it('also brands the plain-text part', function () {
    $tenant = createTenant(['name' => 'Habru Woreda']);
    $reader = brandedMemberOf($tenant, ['letterhead_phone' => '0111']);
    $notification = new LeaveApprovedNotification(brandedLeave($tenant));

    event(new NotificationSending($reader, $notification, 'mail'));
    $text = (string) app(Markdown::class)->renderText('notifications::email', $notification->toMail($reader)->data());

    expect($text)->toContain('Habru Woreda')->toContain('Tel: 0111')->toContain('Sent with ETHR')->not->toContain('Laravel');
});

it('does not brand on a channel other than mail', function () {
    $tenant = createTenant(['name' => 'Habru Woreda']);
    $reader = brandedMemberOf($tenant);
    $other = createTenant(['name' => 'Other Org']);
    $otherReader = brandedMemberOf($other);

    event(new NotificationSending($reader, new LeaveApprovedNotification(brandedLeave($tenant)), 'mail'));
    event(new NotificationSending($otherReader, new LeaveApprovedNotification(brandedLeave($other)), 'database'));

    expect(app(MailBrand::class)->name())->toBe('Habru Woreda');
});

it('keeps a brand-resolution failure from stopping the mail', function () {
    $tenant = createTenant(['name' => 'Habru Woreda']);
    $reader = brandedMemberOf($tenant);
    Log::spy();
    $broken = new class extends User
    {
        public function preferredLocale(): ?string
        {
            throw new RuntimeException('locale lookup failed');
        }
    };
    $broken->tenant_id = $tenant->id;

    event(new NotificationSending($broken, new LeaveApprovedNotification(brandedLeave($tenant)), 'mail'));

    expect(app(MailBrand::class)->isPlatform())->toBeTrue()
        ->and(app(MailBrand::class)->name())->toBe('ETHR');
    Log::shouldHaveReceived('warning')->once();
});

it('puts the right organisation on the mail that actually leaves, send after send', function () {
    $beta = createTenant(['name' => 'Beta Clinic']);
    $betaReader = brandedMemberOf($beta, ['letterhead_phone' => '0222 222']);
    $alpha = createTenant(['name' => 'Alpha Bank']);
    $alphaReader = brandedMemberOf($alpha, ['letterhead_phone' => '0111 111']);

    $betaReader->notifyNow(new LeaveApprovedNotification(brandedLeave($beta)), ['mail']);
    $alphaReader->notifyNow(new LeaveApprovedNotification(brandedLeave($alpha)), ['mail']);

    $sent = array_map(
        fn ($message) => $message->getOriginalMessage()->getHtmlBody(),
        app('mailer')->getSymfonyTransport()->messages()->all(),
    );

    expect($sent)->toHaveCount(2)
        ->and($sent[0])->toContain('Beta Clinic')->toContain('0222 222')
        ->and($sent[1])->toContain('Alpha Bank')->toContain('0111 111')
        ->not->toContain('Beta Clinic')
        ->not->toContain('0222 222')
        ->not->toContain('Laravel');
});

it('brands a report or digest sent to a free-text address with the organisation that owns it, and signs off as it', function () {
    $tenant = createTenant(['name' => 'Habru Woreda']);
    $tenant->update(['settings' => [...($tenant->settings ?? []), 'letterhead_phone' => '0111']]);

    $html = renderBranded(
        (new AnonymousNotifiable)->route('mail', 'finance@example.test')->route('tenant', $tenant->id),
        new DashboardDigestFailedNotification('weekly'),
    );

    expect($html)->toContain('Habru Woreda')
        ->toContain('0111')
        ->toContain('Sent with ETHR')
        ->not->toContain('Laravel');
    // The sign-off names the organisation, not the app.
    expect(preg_match('/Regards,.{0,80}Habru Woreda/su', $html))->toBe(1);
});
