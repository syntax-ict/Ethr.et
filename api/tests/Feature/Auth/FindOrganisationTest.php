<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Models\User;
use App\Notifications\OrganisationSignInLinksNotification;
use App\Services\Auth\OrganisationFinder;
use App\Services\CurrentTenant;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/*
 * "Find my organisation" on the apex login. A person who does not know their
 * organisation's subdomain gives an email address; the answer on screen is
 * always the same sentence, and the sign-in links go to the address itself.
 * Which organisations an address belongs to is never shown — that would turn
 * the apex into a directory of who works where.
 */

const FIND_ORGANISATION_URL = 'http://ethr.et/api/v1/auth/find-organisation';

beforeEach(function () {
    config(['app.domain' => 'ethr.et', 'app.frontend_url' => 'https://ethr.et']);
    Notification::fake();
    RateLimiter::clear('find-organisation|person@example.et|127.0.0.1');
});

/** A user with this address in a fresh tenant, and the apex left with no tenant resolved. */
function memberOf(array $tenant, string $email = 'person@example.et', string $status = 'active'): User
{
    $user = createUser(['email' => $email, 'status' => $status], createTenant($tenant));
    app(CurrentTenant::class)->forget();

    return $user;
}

function findOrganisation(string $email = 'person@example.et')
{
    return test()->postJson(FIND_ORGANISATION_URL, ['email' => $email]);
}

it('answers an address that belongs nowhere with the same sentence, and emails nobody', function () {
    findOrganisation('nobody@example.et')
        ->assertOk()
        ->assertExactJson(['message' => "If that address belongs to an organisation, we've emailed you its sign-in link."]);

    Notification::assertNothingSent();
});

it('emails one message with each organisation\'s own sign-in link', function () {
    memberOf(['subdomain' => 'acme', 'name' => 'Acme Ltd']);
    memberOf(['subdomain' => 'habru', 'name' => 'Habru Textiles']);

    findOrganisation()->assertOk();

    Notification::assertSentOnDemandTimes(OrganisationSignInLinksNotification::class, 1);
    Notification::assertSentOnDemand(
        OrganisationSignInLinksNotification::class,
        function (OrganisationSignInLinksNotification $notification, array $channels, AnonymousNotifiable $notifiable) {
            $mail = $notification->toMail($notifiable);
            $body = implode("\n", [...$mail->introLines, ...$mail->outroLines, (string) $mail->actionUrl]);

            return $notifiable->routes['mail'] === 'person@example.et'
                && str_contains($body, 'https://acme.ethr.et/login')
                && str_contains($body, 'https://habru.ethr.et/login')
                && str_contains($body, 'Acme Ltd')
                && str_contains($body, 'Habru Textiles');
        },
    );
});

it('gives exactly the same response whether or not the address belongs anywhere', function () {
    memberOf(['subdomain' => 'acme', 'name' => 'Acme Ltd']);

    $known = findOrganisation('person@example.et');
    $unknown = findOrganisation('nobody@example.et');

    // A 200 first: without it, two identical 404s from a missing route would
    // pass as "the same response" — which is exactly what this test did against
    // the code before the endpoint existed.
    expect($known->status())->toBe(200)
        ->and($known->status())->toBe($unknown->status())
        ->and($known->getContent())->toBe($unknown->getContent());
});

it('never names an organisation on screen', function () {
    memberOf(['subdomain' => 'acme', 'name' => 'Acme Ltd']);

    $body = findOrganisation()->assertOk()->getContent();

    expect($body)->not->toContain('acme')
        ->and($body)->not->toContain('Acme');
});

it('leaves out organisations the person cannot sign in to', function () {
    memberOf(['subdomain' => 'invited', 'name' => 'Invited Only'], status: 'invited');
    memberOf(['subdomain' => 'suspended', 'name' => 'Suspended Co', 'status' => TenantStatus::SUSPENDED]);
    memberOf(['subdomain' => 'cancelled', 'name' => 'Cancelled Co', 'status' => TenantStatus::CANCELLED]);
    memberOf(['subdomain' => 'expired', 'name' => 'Expired Trial', 'status' => TenantStatus::TRIAL, 'trial_ends_at' => now()->subDay()]);
    memberOf(['subdomain' => 'working', 'name' => 'Working Co', 'status' => TenantStatus::ACTIVE]);

    expect(app(OrganisationFinder::class)->forEmail('person@example.et'))
        ->toBe([['subdomain' => 'working', 'name' => 'Working Co']]);
});

// MariaDB's collation compares `email` case-insensitively and SQLite's does
// not, so an exact match passed in production and failed here — or, worse,
// the other way round for whichever engine a test ran on. Both directions are
// pinned: the address as typed, and the address as stored.
it('finds the organisation whatever the case of the address typed', function () {
    memberOf(['subdomain' => 'demo', 'name' => 'Ethio Demo Corp'], 'admin@demo.ethr.et');

    findOrganisation('Admin@Demo.Ethr.et')->assertOk();

    Notification::assertSentOnDemandTimes(OrganisationSignInLinksNotification::class, 1);
});

it('finds the organisation whatever the case the address was stored in', function () {
    memberOf(['subdomain' => 'demo', 'name' => 'Ethio Demo Corp'], 'Admin@Demo.Ethr.et');

    findOrganisation('admin@demo.ethr.et')->assertOk();

    Notification::assertSentOnDemandTimes(OrganisationSignInLinksNotification::class, 1);
});

it('reads nothing across tenants but each organisation\'s subdomain and name', function () {
    memberOf(['subdomain' => 'acme', 'name' => 'Acme Ltd']);

    $found = app(OrganisationFinder::class)->forEmail('person@example.et');

    expect($found)->toHaveCount(1)
        ->and(array_keys($found[0]))->toBe(['subdomain', 'name']);
});

it('is rate-limited the way the password reset is', function () {
    memberOf(['subdomain' => 'acme', 'name' => 'Acme Ltd']);

    findOrganisation()->assertOk();
    findOrganisation()->assertOk();
    findOrganisation()->assertOk();

    findOrganisation()->assertStatus(429)->assertJsonPath('status', 429);
});

it('asks for a real email address', function () {
    test()->postJson(FIND_ORGANISATION_URL, [])->assertStatus(422)->assertJsonValidationErrors(['email']);
    test()->postJson(FIND_ORGANISATION_URL, ['email' => 'not-an-email'])->assertStatus(422)->assertJsonValidationErrors(['email']);
});
