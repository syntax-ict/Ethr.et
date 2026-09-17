<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantPublicItem;
use App\Models\TenantPublicSection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TenantPublicItem> */
class TenantPublicItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'tenant_id' => Tenant::factory(),
            'section_id' => TenantPublicSection::factory(),
            'position' => 0,
            'title' => fake()->words(3, true),
            'title_am' => null,
            'body' => fake()->sentence(),
            'body_am' => null,
            // No image by default. An image means a stored file, and a factory
            // that created one per item would leave fake storage littered
            // through tests that never asked for it.
            'image_path' => null,
            'image_alt' => null,
            'image_alt_am' => null,
            'icon' => null,
            'link_url' => null,
            'link_label' => null,
            'link_label_am' => null,
            'meta' => null,
        ];
    }
}
