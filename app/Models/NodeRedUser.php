<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NodeRedUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'node_red_instance_id',
        'username',
        'password_hash',
        'permissions',
    ];

    protected $hidden = [
        'password_hash',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(NodeRedInstance::class, 'node_red_instance_id');
    }
}
