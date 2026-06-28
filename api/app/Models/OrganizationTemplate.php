<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class OrganizationTemplate extends Model
{
    use HasPublicId;

    protected $fillable = [
        'public_id',
        'name',
        'slug',
        'description',
        'icon',
        'template_data',
        'is_active',
        'sort_order',
    ];

    protected $hidden = [
        'id',
    ];

    protected function casts(): array
    {
        return [
            'template_data' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
