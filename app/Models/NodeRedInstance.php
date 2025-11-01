<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class NodeRedInstance extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'server_id',
        'plan_id',
        'slug',
        'subdomain',
        'fqdn',
        'memory_mb',
        'storage_gb',
        'admin_user',
        'admin_pass_hash',
        'credential_secret',
        'status',
        'deployed_at',
    ];

    protected $hidden = [
        'admin_pass_hash',
        'credential_secret',
    ];

    protected function casts(): array
    {
        return [
            'deployed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function latestDeployment(): HasOne
    {
        return $this->hasOne(Deployment::class)->latestOfMany();
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($instance) {
            if (empty($instance->slug)) {
                $instance->slug = Str::slug($instance->subdomain ?? Str::random(8));
            }
            if (empty($instance->fqdn) && !empty($instance->subdomain)) {
                $baseDomain = config('provisioning.dns.base_domain', 'nodereds.com');
                $instance->fqdn = $instance->subdomain . '.' . $baseDomain;
            }
        });
    }
}
