<?php

namespace App\Policies;

use App\Models\StreamProfile;
use App\Models\User;

class StreamProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canUseProxy();
    }

    public function view(User $user, StreamProfile $streamProfile): bool
    {
        return $user->canUseProxy() && ($user->isAdmin() || $streamProfile->user_id === $user->id);
    }

    public function create(User $user): bool
    {
        return $user->canUseProxy();
    }

    public function update(User $user, StreamProfile $streamProfile): bool
    {
        return $user->canUseProxy() && ($user->isAdmin() || $streamProfile->user_id === $user->id);
    }

    public function delete(User $user, StreamProfile $streamProfile): bool
    {
        return $user->canUseProxy() && ($user->isAdmin() || $streamProfile->user_id === $user->id);
    }

    public function restore(User $user, StreamProfile $streamProfile): bool
    {
        return $user->isAdmin();
    }

    public function forceDelete(User $user, StreamProfile $streamProfile): bool
    {
        return $user->isAdmin();
    }
}
