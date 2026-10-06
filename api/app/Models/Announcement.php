<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    use BelongsToTenant, HasAuditLog, HasFactory, HasPublicId;

    protected $fillable = [
        'tenant_id',
        'title',
        'body',
        'priority',
        'target_type',
        'target_id',
        'published_by',
        'published_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'target_id' => 'integer',
        ];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * Who may read an announcement. Managers see every one, since they author
     * and edit them; everyone else sees those for the whole tenant plus those
     * aimed at their own department or branch. NotifyAnnouncementAudienceJob
     * already honoured the target, but the list and the single read did not,
     * so a department-only announcement was readable by the whole tenant.
     *
     * @param  Builder<Announcement>  $query
     * @return Builder<Announcement>
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user?->hasPermission('announcement.manage')) {
            return $query;
        }

        $employee = $user?->employee;

        return $query->where(function (Builder $q) use ($employee): void {
            $q->where('target_type', 'all');

            if ($employee?->department_id !== null) {
                $q->orWhere(fn (Builder $q) => $q->where('target_type', 'department')
                    ->where('target_id', $employee->department_id));
            }

            if ($employee?->branch_id !== null) {
                $q->orWhere(fn (Builder $q) => $q->where('target_type', 'branch')
                    ->where('target_id', $employee->branch_id));
            }
        });
    }

    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }
}
