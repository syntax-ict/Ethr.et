<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Enums\ConfigurationSource;

/**
 * A scored, explainable configuration proposal for a tenant.
 *
 * Each section (departments, positions, grades, shifts, leave_types, branches,
 * settings) carries its provenance and a confidence derived purely from that
 * provenance — see ConfigurationSource and ONBOARDING_V2.md decision D2. The
 * flattened {@see self::plan()} is exactly the `template_data` shape that
 * OrganizationProvisioner consumes, so the review step can hand an edited copy
 * straight to apply.
 */
final class ConfigurationPlan
{
    /**
     * @param  array{key: string, label: string, label_am: string, base: string, icon: string, group: string, overrides?: array<string, mixed>}  $industry
     * @param  array<string, mixed>  $signals
     * @param  array<string, array{source: ConfigurationSource, items: mixed}>  $sections
     */
    public function __construct(
        private readonly array $industry,
        private readonly array $signals,
        private readonly array $sections,
    ) {}

    /**
     * The provisioner-ready configuration: section name => items, for every
     * section that produced anything.
     *
     * @return array<string, mixed>
     */
    public function plan(): array
    {
        $plan = [];

        foreach ($this->sections as $name => $section) {
            $plan[$name] = $section['items'];
        }

        return $plan;
    }

    /**
     * Mean of the section confidences, rounded to two places. An empty plan is
     * reported as 0.0 rather than dividing by zero.
     */
    public function overallConfidence(): float
    {
        if ($this->sections === []) {
            return 0.0;
        }

        $sum = 0.0;

        foreach ($this->sections as $section) {
            $sum += $section['source']->confidence();
        }

        return round($sum / count($this->sections), 2);
    }

    /**
     * @return array{
     *     industry: array{key: string, label: string, label_am: string, base: string},
     *     signals: array<string, mixed>,
     *     overall_confidence: float,
     *     sections: array<string, array{source: string, confidence: float, items: mixed}>,
     *     plan: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        $sections = [];

        foreach ($this->sections as $name => $section) {
            $sections[$name] = [
                'source' => $section['source']->value,
                'confidence' => $section['source']->confidence(),
                'items' => $section['items'],
            ];
        }

        return [
            'industry' => [
                'key' => $this->industry['key'],
                'label' => $this->industry['label'],
                'label_am' => $this->industry['label_am'],
                'base' => $this->industry['base'],
            ],
            'signals' => $this->signals,
            'overall_confidence' => $this->overallConfidence(),
            'sections' => $sections,
            'plan' => $this->plan(),
        ];
    }
}
