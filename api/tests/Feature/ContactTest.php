<?php

declare(strict_types=1);

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
