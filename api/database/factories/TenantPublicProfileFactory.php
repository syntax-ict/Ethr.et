<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantPublicProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TenantPublicProfile> */
class TenantPublicProfileFactory extends Factory
{
    /**
     * Unpublished by default, matching the column default.
     *
     * A factory that published by default would quietly make every test fixture
     * internet-visible, and the one test that matters — that an unpublished
     * tenant 404s — would pass for the wrong reason.
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'tenant_id' => Tenant::factory(),
            'is_published' => false,
            'is_indexable' => true,
            'headline' => fake()->catchPhrase(),
            'description' => fake()->paragraph(),
            'contact_email' => fake()->companyEmail(),
            'contact_phone' => '+2519'.fake()->numerify('########'),
            'address_line' => fake()->streetAddress(),
            'city' => 'Addis Ababa',
            'region' => 'Addis Ababa',
            'website_url' => 'https://'.fake()->domainName(),
            'social_links' => ['telegram' => 'https://t.me/'.fake()->userName()],
            'meta_description' => fake()->sentence(),
        ];
    }

    public function published(): static
    {
        return $this->state([
            'is_published' => true,
            'published_at' => now(),
        ]);
    }

    public function notIndexable(): static
    {
        return $this->state(['is_indexable' => false]);
    }
}
