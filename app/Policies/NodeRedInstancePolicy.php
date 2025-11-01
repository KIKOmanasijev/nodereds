<?php

namespace App\Policies;

use App\Models\NodeRedInstance;
use App\Models\User;

class NodeRedInstancePolicy
{
    /**
     * Determine if the user can view any instances.
     */
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine if the user can view the instance.
     */
    public function view(User $user, NodeRedInstance $instance): bool
    {
        return $user->isSuperAdmin() || $user->id === $instance->user_id;
    }

    /**
     * Determine if the user can create instances.
     */
    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine if the user can update the instance.
     */
    public function update(User $user, NodeRedInstance $instance): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine if the user can delete the instance.
     */
    public function delete(User $user, NodeRedInstance $instance): bool
    {
        return $user->isSuperAdmin();
    }
}
