<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PublicSectionKind;
use App\Models\Tenant;
use App\Models\TenantPublicSection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TenantPublicSection> */
class TenantPublicSectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'tenant_id' => Tenant::factory(),
            'kind' => PublicSectionKind::SERVICES,
            'position' => 0,
            // Visible by default, unlike the seeded-but-empty sections an
            // opt-in creates: a factory-made section exists because a test
            // wants to see something rendered.
            'is_visible' => true,
            'heading' => fake()->catchPhrase(),
            'heading_am' => 'አገልግሎቶች',
            'intro' => fake()->sentence(),
            'intro_am' => null,
            'layout' => null,
            'options' => null,
        ];
    }

    public function kind(PublicSectionKind $kind): self
    {
        return $this->state(fn () => ['kind' => $kind]);
    }

    public function hidden(): self
    {
        return $this->state(fn () => ['is_visible' => false]);
    }
}
