<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'memory_mb',
        'storage_gb',
        'cpu_count',
        'cpu_limit',
        'monthly_price_cents',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'cpu_limit' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($plan) {
            if (empty($plan->slug)) {
                $plan->slug = Str::slug($plan->name);
            }
        });
    }

    public function nodeRedInstances(): HasMany
    {
        return $this->hasMany(NodeRedInstance::class);
    }
}
