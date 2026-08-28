<?php

declare(strict_types=1);

use App\Services\Observability\SentryScrubber;
use Sentry\Breadcrumb;
use Sentry\Event;

it('redacts credentials and encrypted-at-rest fields from the request payload', function () {
    $event = Event::createEvent();
    $event->setRequest([
        'url' => 'https://acme.ethr.et/api/v1/employees',
        'method' => 'POST',
        'data' => [
            'first_name' => 'Abebe',
            'password' => 'hunter2',
            'national_id' => '1234567890',
            'tin' => '0098765432',
            'account_number' => '1000200030004000',
            'totp_secret' => 'JBSWY3DPEHPK3PXP',
        ],
        'headers' => [
            'authorization' => 'Bearer super-secret-token',
            'cookie' => 'session=abc123',
            'content-type' => 'application/json',
        ],
    ]);

    $request = SentryScrubber::handle($event)?->getRequest();

    expect($request['data']['password'])->toBe('[redacted]')
        ->and($request['data']['national_id'])->toBe('[redacted]')
        ->and($request['data']['tin'])->toBe('[redacted]')
        ->and($request['data']['account_number'])->toBe('[redacted]')
        ->and($request['data']['totp_secret'])->toBe('[redacted]')
        ->and($request['headers']['authorization'])->toBe('[redacted]')
        ->and($request['headers']['cookie'])->toBe('[redacted]');

    // Non-sensitive context must survive, or the report is useless for debugging.
    expect($request['data']['first_name'])->toBe('Abebe')
        ->and($request['headers']['content-type'])->toBe('application/json')
        ->and($request['method'])->toBe('POST');
});

it('redacts sensitive keys nested at any depth', function () {
    $event = Event::createEvent();
    $event->setRequest([
        'data' => [
            'employee' => [
                'bank_details' => [
                    ['bank_name' => 'CBE', 'account_number' => '1000200030004000'],
                ],
            ],
        ],
    ]);

    $request = SentryScrubber::handle($event)?->getRequest();

    expect($request['data']['employee']['bank_details'][0]['account_number'])->toBe('[redacted]')
        ->and($request['data']['employee']['bank_details'][0]['bank_name'])->toBe('CBE');
});

it('redacts fields that merely contain a sensitive fragment', function () {
    $event = Event::createEvent();
    $event->setExtra([
        'employee_national_id' => '1234567890',
        'sso_client_secret' => 'shhh',
        'reset_password_token' => 'abc',
        'department_name' => 'Finance',
    ]);

    $extra = SentryScrubber::handle($event)?->getExtra();

    expect($extra['employee_national_id'])->toBe('[redacted]')
        ->and($extra['sso_client_secret'])->toBe('[redacted]')
        ->and($extra['reset_password_token'])->toBe('[redacted]')
        ->and($extra['department_name'])->toBe('Finance');
});

it('redacts sensitive metadata attached to breadcrumbs', function () {
    $event = Event::createEvent();
    $event->setBreadcrumb([
        new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_DEFAULT,
            'log',
            'Employee updated',
            [
                'employee' => 'Abebe Kebede',
                'national_id' => '1234567890',
                'account_number' => '1000200030004000',
            ],
        ),
    ]);

    $crumbs = SentryScrubber::handle($event)?->getBreadcrumbs();
    $metadata = $crumbs[0]->getMetadata();

    expect($metadata['national_id'])->toBe('[redacted]')
        ->and($metadata['account_number'])->toBe('[redacted]')
        ->and($metadata['employee'])->toBe('Abebe Kebede');

    // The rest of the breadcrumb must survive rebuilding, or the trail is useless.
    expect($crumbs[0]->getMessage())->toBe('Employee updated')
        ->and($crumbs[0]->getCategory())->toBe('log')
        ->and($crumbs[0]->getLevel())->toBe(Breadcrumb::LEVEL_INFO);
});

// The tenant tag needs a booted container, so it lives in
// tests/Feature/SentryTenantTagTest.php — Unit tests here stay app-free and fast.

it('returns the event unchanged when there is nothing to scrub', function () {
    $event = Event::createEvent();

    expect(SentryScrubber::handle($event))->toBe($event);
});
