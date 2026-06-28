<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'name',
        'slug',
        'price_cents',
        'max_employees',
        'max_branches',
        'max_devices',
        'features',
        'is_active',
        'sort_order',
    ];

    protected $hidden = [
        'id',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'max_employees' => 'integer',
            'max_branches' => 'integer',
            'max_devices' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
