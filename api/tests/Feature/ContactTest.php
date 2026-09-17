<?php

declare(strict_types=1);

use App\Models\Lead;
use App\Notifications\NewLeadNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * The five validation cases below predate the handler doing anything. They all
 * passed while `ContactController` was a single `Log::info` that threw the
 * enquiry away, because every one of them asserted a status code around the
 * body rather than an outcome from it — the failure the PR template names as
 * "tests pass is not evidence".
 *
 * They are kept: validation is still worth pinning. What follows them is the
 * part that can actually fail if the lead stops being saved.
 */
describe('POST /api/v1/contact', function () {
    it('accepts valid contact form submission', function () {
        $response = $this->postJson('/api/v1/contact', [
            'name' => 'Abebe Kebede',
            'email' => 'abebe@example.com',
            'phone' => '+251911234567',
            'organization' => 'Test Corp',
            'message' => 'I would like to learn more about ETHR for our organization.',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['message']);
    });

    it('validates required fields', function () {
        $response = $this->postJson('/api/v1/contact', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'message']);
    });

    it('validates email format', function () {
        $response = $this->postJson('/api/v1/contact', [
            'name' => 'Test User',
            'email' => 'not-an-email',
            'message' => 'This is a test message for validation.',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });

    it('validates minimum message length', function () {
        $response = $this->postJson('/api/v1/contact', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'message' => 'Short',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['message']);
    });

    it('allows optional fields to be null', function () {
        $response = $this->postJson('/api/v1/contact', [
            'name' => 'Abebe Kebede',
            'email' => 'abebe@example.com',
            'message' => 'I want to know more about ETHR pricing.',
        ]);

        $response->assertCreated();
    });
});

describe('POST /api/v1/contact — the lead itself', function () {
    it('persists the enquiry', function () {
        Notification::fake();

        $this->postJson('/api/v1/contact', [
            'name' => 'Selam Tesfaye',
            'email' => 'selam@habru.example',
            'phone' => '+251911234567',
            'organization' => 'Habru Textiles',
            'message' => 'We have four factory sites and need offline attendance.',
        ])->assertCreated();

        $lead = Lead::sole();

        expect($lead->name)->toBe('Selam Tesfaye')
            ->and($lead->email)->toBe('selam@habru.example')
            ->and($lead->organization)->toBe('Habru Textiles')
            ->and($lead->message)->toContain('four factory sites');
    });

    it('announces the lead to the configured inbox', function () {
        Notification::fake();
        config(['mail.contact_inbox' => 'sales@ethr.example']);

        $this->postJson('/api/v1/contact', [
            'name' => 'Selam Tesfaye',
            'email' => 'selam@habru.example',
            'message' => 'We would like a demo for our head office.',
        ])->assertCreated();

        Notification::assertSentOnDemand(NewLeadNotification::class);
    });

    it('still captures the lead when no inbox is configured', function () {
        Notification::fake();
        config(['mail.contact_inbox' => null]);

        $this->postJson('/api/v1/contact', [
            'name' => 'Selam Tesfaye',
            'email' => 'selam@habru.example',
            'message' => 'We would like a demo for our head office.',
        ])->assertCreated();

        expect(Lead::count())->toBe(1);
        Notification::assertNothingSent();
    });

    it('keeps the lead when the notification throws', function () {
        // The lead is already in the database by the time mail is attempted. A
        // dead SMTP host must not turn that into a 500 and a second, confused
        // submission from the sender.
        config(['mail.contact_inbox' => 'sales@ethr.example']);

        Notification::shouldReceive('route')->andThrow(new RuntimeException('smtp down'));

        $this->postJson('/api/v1/contact', [
            'name' => 'Selam Tesfaye',
            'email' => 'selam@habru.example',
            'message' => 'We would like a demo for our head office.',
        ])->assertCreated();

        expect(Lead::count())->toBe(1);
    });

    it('never writes the sender\'s details to the log', function () {
        Notification::fake();

        // The old handler logged the whole validated payload. This is the
        // regression test for that specific line.
        Log::spy();

        $this->postJson('/api/v1/contact', [
            'name' => 'Selam Tesfaye',
            'email' => 'selam@habru.example',
            'phone' => '+251911234567',
            'message' => 'We would like a demo for our head office.',
        ])->assertCreated();

        Log::shouldNotHaveReceived('info', function (string $message, array $context = []) {
            return str_contains(json_encode($context) ?: '', 'selam@habru.example');
        });
    });

    it('accepts and discards a submission that fills the honeypot', function () {
        Notification::fake();

        // Same 201 a person gets: telling a bot it failed only teaches it which
        // field to leave alone next time.
        $this->postJson('/api/v1/contact', [
            'name' => 'Selam Tesfaye',
            'email' => 'selam@habru.example',
            'message' => 'We would like a demo for our head office.',
            'website' => 'http://spam.example',
        ])->assertCreated();

        expect(Lead::count())->toBe(0);
        Notification::assertNothingSent();
    });
});
