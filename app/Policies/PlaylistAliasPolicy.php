<?php

namespace App\Policies;

use App\Models\PlaylistAlias;
use App\Models\User;

class PlaylistAliasPolicy
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
    public function view(User $user, PlaylistAlias $playlistAlias): bool
    {
        return $this->owns($user, $playlistAlias);
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
    public function update(User $user, PlaylistAlias $playlistAlias): bool
    {
        return $this->owns($user, $playlistAlias);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, PlaylistAlias $playlistAlias): bool
    {
        return $this->owns($user, $playlistAlias);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, PlaylistAlias $playlistAlias): bool
    {
        return $this->owns($user, $playlistAlias);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, PlaylistAlias $playlistAlias): bool
    {
        return $this->owns($user, $playlistAlias);
    }

    /**
     * PlaylistAlias doesn't have direct user_id, check through the source playlist, which
     * may be either a standard or a custom playlist.
     */
    private function owns(User $user, PlaylistAlias $playlistAlias): bool
    {
        return $user->isAdmin() || $user->id === $playlistAlias->getEffectivePlaylist()?->user_id;
    }
}
