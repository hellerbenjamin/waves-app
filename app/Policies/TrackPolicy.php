<?php

namespace App\Policies;

use App\Models\Track;
use App\Models\User;

class TrackPolicy
{
    public function view(User $user, Track $track): bool
    {
        return $track->user_id === $user->id;
    }

    public function update(User $user, Track $track): bool
    {
        return $track->user_id === $user->id;
    }

    public function delete(User $user, Track $track): bool
    {
        return $track->user_id === $user->id;
    }
}
