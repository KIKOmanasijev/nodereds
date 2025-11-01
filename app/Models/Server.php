<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Server extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_id',
        'name',
        'public_ip',
        'private_ip',
        'region',
        'server_type',
        'ram_mb_total',
        'disk_gb_total',
        'ram_mb_used',
        'disk_gb_used',
        'status',
        'provisioned_at',
    ];

    protected function casts(): array
    {
        return [
            'provisioned_at' => 'datetime',
        ];
    }

    public function nodeRedInstances(): HasMany
    {
        return $this->hasMany(NodeRedInstance::class);
    }

    /**
     * Get allocated memory (sum of all instance memory allocations).
     */
    public function getAllocatedMemoryMbAttribute(): int
    {
        return $this->nodeRedInstances()->sum('memory_mb');
    }

    /**
     * Get allocated storage (sum of all instance storage allocations).
     */
    public function getAllocatedDiskGbAttribute(): int
    {
        return $this->nodeRedInstances()->sum('storage_gb');
    }

    public function getAvailableMemoryMbAttribute(): int
    {
        $reserved = config('provisioning.reserved_resources.memory_mb', 512);
        $allocated = $this->allocated_memory_mb;
        return max(0, $this->ram_mb_total - $allocated - $reserved);
    }

    public function getAvailableDiskGbAttribute(): int
    {
        $reserved = config('provisioning.reserved_resources.disk_gb', 10);
        $allocated = $this->allocated_disk_gb;
        return max(0, $this->disk_gb_total - $allocated - $reserved);
    }

    public function canFitInstance(int $memoryMb, int $storageGb): bool
    {
        return $this->available_memory_mb >= $memoryMb && $this->available_disk_gb >= $storageGb;
    }
}
