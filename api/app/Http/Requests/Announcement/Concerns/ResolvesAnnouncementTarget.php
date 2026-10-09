<?php

declare(strict_types=1);

namespace App\Http\Requests\Announcement\Concerns;

use App\Models\Branch;
use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Turns an announcement's `target_id` — a department or branch public_id, the
 * only identifier the API ever hands out — into the internal id the
 * `announcements.target_id` column and NotifyAnnouncementAudienceJob use.
 *
 * Before this, the request accepted any string and the model cast it to an
 * integer, so a public_id became a meaningless number and targeting could not
 * be used through the API at all. `role` targeting is not offered: the column
 * is a bigint and cannot hold a role name.
 *
 * The lookup runs through the tenant global scope, so a public_id from another
 * tenant is refused with the same message as one that does not exist — the
 * shape ValidatesRelationTenancy uses for employee relations, for the same
 * reason.
 *
 * @mixin FormRequest
 */
trait ResolvesAnnouncementTarget
{
    private const TARGET_MODELS = [
        'department' => Department::class,
        'branch' => Branch::class,
    ];

    private ?int $resolvedTargetId = null;

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = $this->input('target_type');
            if (! is_string($type) || ! isset(self::TARGET_MODELS[$type]) || $validator->errors()->has('target_type')) {
                return;
            }

            $publicId = $this->input('target_id');
            if (! is_string($publicId) || $publicId === '') {
                $validator->errors()->add('target_id', __('validation.required', ['attribute' => 'target id']));

                return;
            }

            $model = self::TARGET_MODELS[$type]::query()->where('public_id', $publicId)->first();
            if ($model === null) {
                $validator->errors()->add('target_id', __('validation.exists', ['attribute' => 'target id']));

                return;
            }

            $this->resolvedTargetId = $model->id;
        });
    }

    /**
     * The target to store, or null when the request does not set one.
     *
     * @return array{target_type: string, target_id: int|null}|null
     */
    public function resolvedTarget(): ?array
    {
        $type = $this->validated('target_type');
        if (! is_string($type)) {
            return null;
        }

        return [
            'target_type' => $type,
            'target_id' => isset(self::TARGET_MODELS[$type]) ? $this->resolvedTargetId : null,
        ];
    }
}
