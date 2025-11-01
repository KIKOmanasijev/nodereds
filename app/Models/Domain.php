<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Domain extends Model
{
    use HasFactory;

    protected $fillable = [
        'node_red_instance_id',
        'hostname',
        'fqdn',
        'provider',
        'provider_record_id',
        'ssl_status',
        'ssl_issued_at',
        'ssl_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'ssl_issued_at' => 'datetime',
            'ssl_expires_at' => 'datetime',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(NodeRedInstance::class, 'node_red_instance_id');
    }
}
