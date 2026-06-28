<?php

declare(strict_types=1);

use App\Models\Plan;

describe('GET /api/v1/plans', function () {
    it('returns active plans', function () {
        Plan::factory()->create(['name' => 'Starter', 'slug' => 'starter', 'sort_order' => 1, 'is_active' => true]);
        Plan::factory()->create(['name' => 'Pro', 'slug' => 'pro', 'sort_order' => 2, 'is_active' => true]);
        Plan::factory()->create(['name' => 'Inactive', 'slug' => 'inactive', 'sort_order' => 3, 'is_active' => false]);

        $response = $this->getJson('/api/v1/plans');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Starter')
            ->assertJsonPath('data.1.name', 'Pro');
    });

    it('returns plans sorted by sort_order', function () {
        Plan::factory()->create(['name' => 'Enterprise', 'slug' => 'enterprise', 'sort_order' => 3]);
        Plan::factory()->create(['name' => 'Starter', 'slug' => 'starter', 'sort_order' => 1]);
        Plan::factory()->create(['name' => 'Pro', 'slug' => 'pro', 'sort_order' => 2]);

        $response = $this->getJson('/api/v1/plans');

        $response->assertOk()
            ->assertJsonPath('data.0.name', 'Starter')
            ->assertJsonPath('data.1.name', 'Pro')
            ->assertJsonPath('data.2.name', 'Enterprise');
    });

    it('hides numeric ids', function () {
        Plan::factory()->create(['slug' => 'test-plan']);

        $response = $this->getJson('/api/v1/plans');

        $response->assertOk()
            ->assertJsonMissingPath('data.0.id');
    });
});
