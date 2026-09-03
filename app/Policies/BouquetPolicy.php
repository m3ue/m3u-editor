<?php

namespace App\Policies;

use App\Models\Bouquet;
use App\Models\User;

class BouquetPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Bouquet $bouquet): bool
    {
        return $this->owns($user, $bouquet);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Bouquet $bouquet): bool
    {
        return $this->owns($user, $bouquet);
    }

    public function delete(User $user, Bouquet $bouquet): bool
    {
        return $this->owns($user, $bouquet);
    }

    public function restore(User $user, Bouquet $bouquet): bool
    {
        return $this->owns($user, $bouquet);
    }

    public function forceDelete(User $user, Bouquet $bouquet): bool
    {
        return $this->owns($user, $bouquet);
    }

    private function owns(User $user, Bouquet $bouquet): bool
    {
        return $user->isAdmin() || $user->id === $bouquet->user_id;
    }
}
