<?php

namespace App\Policies;

use App\Models\EmbyLibraryMapping;
use App\Models\User;

class EmbyLibraryMappingPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->canUseIntegrations();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, EmbyLibraryMapping $embyLibraryMapping): bool
    {
        return $user->canUseIntegrations()
            && ($user->isAdmin() || $user->id === $embyLibraryMapping->user_id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->canUseIntegrations();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, EmbyLibraryMapping $embyLibraryMapping): bool
    {
        return $user->canUseIntegrations()
            && ($user->isAdmin() || $user->id === $embyLibraryMapping->user_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, EmbyLibraryMapping $embyLibraryMapping): bool
    {
        return $user->canUseIntegrations()
            && ($user->isAdmin() || $user->id === $embyLibraryMapping->user_id);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, EmbyLibraryMapping $embyLibraryMapping): bool
    {
        return $user->canUseIntegrations()
            && ($user->isAdmin() || $user->id === $embyLibraryMapping->user_id);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, EmbyLibraryMapping $embyLibraryMapping): bool
    {
        return $user->canUseIntegrations()
            && ($user->isAdmin() || $user->id === $embyLibraryMapping->user_id);
    }
}
