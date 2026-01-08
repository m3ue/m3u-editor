<?php

namespace App\Policies;

use App\Models\MergedPlaylist;
use App\Models\User;

class MergedPlaylistPolicy
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
    public function view(User $user, MergedPlaylist $mergedPlaylist): bool
    {
        return $user->isAdmin() || $user->id === $mergedPlaylist->user_id;
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
    public function update(User $user, MergedPlaylist $mergedPlaylist): bool
    {
        return $user->isAdmin() || $user->id === $mergedPlaylist->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, MergedPlaylist $mergedPlaylist): bool
    {
        return $user->isAdmin() || $user->id === $mergedPlaylist->user_id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, MergedPlaylist $mergedPlaylist): bool
    {
        return $user->isAdmin() || $user->id === $mergedPlaylist->user_id;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, MergedPlaylist $mergedPlaylist): bool
    {
        return $user->isAdmin() || $user->id === $mergedPlaylist->user_id;
    }
}
