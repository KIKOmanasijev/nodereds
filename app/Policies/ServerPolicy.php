<?php

namespace App\Policies;

use App\Models\Server;
use App\Models\User;

class ServerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, Server $server): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, Server $server): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, Server $server): bool
    {
        return $user->isSuperAdmin();
    }
}
