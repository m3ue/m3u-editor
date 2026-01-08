<?php

namespace App\Policies;

use App\Models\Epg;
use App\Models\User;

class EpgPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Epg $epg): bool
    {
        return $user->isAdmin() || $user->id === $epg->user_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Epg $epg): bool
    {
        return $user->isAdmin() || $user->id === $epg->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Epg $epg): bool
    {
        return $user->isAdmin() || $user->id === $epg->user_id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Epg $epg): bool
    {
        return $user->isAdmin() || $user->id === $epg->user_id;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Epg $epg): bool
    {
        return $user->isAdmin() || $user->id === $epg->user_id;
    }
}
