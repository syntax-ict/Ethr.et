<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\PublicSectionKind;
use App\Models\TenantPublicSection;
use App\Services\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for one block on a tenant's public page.
 *
 * Everything here is rendered to anonymous visitors as plain text — the
 * templates escape, and no public view contains an unescaped echo outside the
 * JSON-LD block, which a test enforces. So these rules are about length and
 * shape rather than sanitisation: HTML typed into a heading appears as the
 * characters that were typed, which is the correct behaviour for a field with
 * no rich-text editor behind it.
 *
 * The cap on sections is the rule worth keeping. Without it a tenant can add
 * blocks until the page is megabytes and the storage bill is someone else's
 * problem — on shared hosting, for a page served to anyone who asks.
 */
class UpsertPublicSectionRequest extends FormRequest
{
    /** The most blocks one tenant may put on its page. */
    public const MAX_SECTIONS = 20;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => [
                $this->isCreating() ? 'required' : 'sometimes',
                Rule::enum(PublicSectionKind::class),
                $this->isCreating() ? $this->withinSectionCap() : 'nullable',
                $this->isCreating() ? $this->notAlreadyPresent() : 'nullable',
            ],
            'is_visible' => ['sometimes', 'boolean'],
            'heading' => ['sometimes', 'nullable', 'string', 'max:160'],
            'heading_am' => ['sometimes', 'nullable', 'string', 'max:160'],
            'intro' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'intro_am' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'layout' => ['sometimes', 'nullable', 'string', 'max:32', Rule::in(['grid', 'list', 'columns'])],
        ];
    }

    /**
     * Refuse a second copy of a kind that may only appear once.
     *
     * Hero, about and contact read the profile rather than storing their own
     * content, so a second one is a duplicate of the same text — and for the
     * hero it is worse than redundant, because it puts a second <h1> on the
     * page and breaks the document outline.
     */
    private function notAlreadyPresent(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $kind = PublicSectionKind::tryFrom(is_string($value) ? $value : '');

            if ($kind === null || ! $kind->readsProfile()) {
                return;
            }

            $tenant = app(CurrentTenant::class)->get();

            if ($tenant === null) {
                return;
            }

            $exists = TenantPublicSection::query()
                ->where('tenant_id', $tenant->id)
                ->where('kind', $kind->value)
                ->exists();

            if ($exists) {
                $fail(__('This page already has a :kind section.', ['kind' => $kind->value]));
            }
        };
    }

    private function isCreating(): bool
    {
        return $this->isMethod('POST');
    }

    /**
     * Refuse a new section once the tenant is at the cap.
     *
     * Counted at validation time rather than enforced by a database constraint
     * because the answer has to be a 422 an administrator can read, not a
     * constraint violation surfacing as a 500.
     */
    private function withinSectionCap(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $tenant = app(CurrentTenant::class)->get();

            if ($tenant === null) {
                return;
            }

            $count = TenantPublicSection::query()
                ->where('tenant_id', $tenant->id)
                ->count();

            if ($count >= self::MAX_SECTIONS) {
                $fail(__('A page may have at most :max sections.', ['max' => self::MAX_SECTIONS]));
            }
        };
    }
}
