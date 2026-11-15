<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Position;
use App\Services\Onboarding\IndustryCatalog;
use App\Services\Onboarding\IndustryProfileResolver;
use App\Services\Onboarding\ProposalNameGlossary;
use Database\Seeders\OrganizationTemplateSeeder;

/*
 * The setup wizard's proposal named its departments, positions and grades in
 * English on an Amharic or Afaan Oromoo screen, and the kind of organisation
 * in law had no Oromo name (QA 2026-11-14, O-2). The catalogue and the
 * templates still name them in English; ProposalNameGlossary carries the
 * other two languages and the resolver adds them to the proposal.
 */
describe('the proposal names its departments, positions and grades in three languages', function () {
    beforeEach(function () {
        $this->seed(OrganizationTemplateSeeder::class);
        createTenant();
    });

    it('gives every proposed department and position an Amharic and an Afaan Oromoo name', function () {
        $resolver = app(IndustryProfileResolver::class);
        $checked = 0;

        foreach (app(IndustryCatalog::class)->keys() as $industry) {
            $sections = $resolver->resolve($industry)->toArray()['sections'];

            foreach (['departments' => 'name', 'positions' => 'title'] as $section => $field) {
                foreach ($sections[$section]['items'] ?? [] as $item) {
                    $where = "{$industry} {$section}: ".json_encode($item, JSON_UNESCAPED_UNICODE);

                    expect($item)->toBeArray($where);
                    $english = $item[$field];

                    foreach (['am', 'om'] as $language) {
                        $text = $item["{$field}_{$language}"] ?? '';

                        expect($text)->toBeString($where)
                            ->and(trim($text))->not->toBe('', $where)
                            ->and($text)->not->toBe($english, $where)
                            ->and($text)->not->toContain('—')
                            ->and($text)->not->toMatch('/!\s*$/u');
                    }

                    // Amharic is written in Ethiopic script and Afaan Oromoo
                    // in Latin: a value in the wrong column is caught here.
                    expect($item["{$field}_am"])->toMatch('/\p{Ethiopic}/u', $where)
                        ->and($item["{$field}_om"])->not->toMatch('/\p{Ethiopic}/u', $where);

                    $checked++;
                }
            }
        }

        expect($checked)->toBeGreaterThan(300);
    });

    it('translates the word of a grade and keeps its number', function () {
        $grades = app(IndustryProfileResolver::class)->resolve('bank')->toArray()['sections']['grades']['items'];

        expect($grades)->toHaveCount(8);
        expect($grades[3])->toMatchArray(['name' => 'Grade IV', 'name_am' => 'ደረጃ IV', 'name_om' => 'Sadarkaa IV']);
        expect($grades[3]['min_salary_cents'])->toBeInt();
    });

    it('leaves a name it does not know in English, as a record', function () {
        $glossary = app(ProposalNameGlossary::class);

        expect($glossary->department('People Ops'))->toBe(['name' => 'People Ops'])
            ->and($glossary->position(['name' => 'Chief Bottle Washer']))->toBe(['name' => 'Chief Bottle Washer'])
            ->and($glossary->grade(['name' => 'Band 3']))->toBe(['name' => 'Band 3']);
    });

    it('keeps the Amharic a template already wrote', function () {
        $named = app(ProposalNameGlossary::class)->department(['name' => 'Finance', 'name_am' => 'የገንዘብ ክፍል']);

        expect($named['name_am'])->toBe('የገንዘብ ክፍል')
            ->and($named['name_om'])->toBe('Faayinaansii');
    });
});

describe('applying the proposal', function () {
    it('stores the Amharic name of a department and a position, and no Oromo one', function () {
        $this->seed(OrganizationTemplateSeeder::class);
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $host = 'http://'.$tenant->subdomain.'.ethr.test/api/v1/onboarding/configuration';

        $plan = $this->postJson($host.'/preview', ['industry' => 'technology_company'])->assertOk()->json('plan');

        expect($plan['departments'][0])->toMatchArray(['name' => 'Engineering', 'name_am' => 'ምህንድስና', 'name_om' => 'Injinariingii']);

        $this->postJson($host.'/apply', [
            'industry' => 'technology_company',
            'plan' => ['departments' => $plan['departments'], 'positions' => $plan['positions'], 'grades' => $plan['grades'], 'holidays' => false],
        ])->assertOk();

        $department = Department::where('tenant_id', $tenant->id)->where('name', 'Engineering')->firstOrFail();
        $position = Position::where('tenant_id', $tenant->id)->where('title', 'Software Engineer')->firstOrFail();

        expect($department->name_am)->toBe('ምህንድስና')
            ->and($position->title_am)->toBe('የሶፍትዌር መሐንዲስ')
            ->and($department->getAttributes())->not->toHaveKey('name_om');
    });
});

describe('GET /organization-types', function () {
    it('gives every kind an Afaan Oromoo name that is not its English one', function () {
        $rows = collect($this->getJson('/api/v1/organization-types')->assertOk()->json('data'));

        expect($rows)->toHaveCount(16);

        foreach ($rows as $row) {
            expect($row['name_om'])->toBeString($row['code'])
                ->and(trim($row['name_om']))->not->toBe('', $row['code'])
                ->and($row['name_om'])->not->toBe($row['name'], $row['code'])
                ->and($row['name_om'])->not->toMatch('/\p{Ethiopic}/u', $row['code']);
        }

        expect($rows->firstWhere('code', 'private')['name_om'])->toBe('Dhaabbata Dhuunfaa');
    });
});
