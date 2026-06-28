<?php

declare(strict_types=1);

it('returns the welcome page', function () {
    $this->get('/')->assertStatus(200);
});

it('returns the health check', function () {
    $this->get('/up')->assertStatus(200);
});

it('returns the api ping', function () {
    $this->getJson('/api/v1/ping')
        ->assertOk()
        ->assertJsonStructure(['status', 'timestamp']);
});
