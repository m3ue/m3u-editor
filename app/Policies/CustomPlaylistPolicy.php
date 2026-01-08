<?php

namespace App\Policies;

use App\Models\CustomPlaylist;
use App\Models\User;

class CustomPlaylistPolicy
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
    public function view(User $user, CustomPlaylist $customPlaylist): bool
    {
        return $user->isAdmin() || $user->id === $customPlaylist->user_id;
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
    public function update(User $user, CustomPlaylist $customPlaylist): bool
    {
        return $user->isAdmin() || $user->id === $customPlaylist->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, CustomPlaylist $customPlaylist): bool
    {
        return $user->isAdmin() || $user->id === $customPlaylist->user_id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, CustomPlaylist $customPlaylist): bool
    {
        return $user->isAdmin() || $user->id === $customPlaylist->user_id;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, CustomPlaylist $customPlaylist): bool
    {
        return $user->isAdmin() || $user->id === $customPlaylist->user_id;
    }
}
