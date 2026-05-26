<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\EventInvite;
use App\Models\User;

/**
 * Only an event's owner may mint or revoke its contribution links. Contributors
 * never touch these endpoints — they are authorized by the token itself on the
 * public routes, where no policy runs.
 */
class EventInvitePolicy
{
    /** Mint a new invite for an event the user owns. */
    public function create(User $user, Event $event): bool
    {
        return $event->user_id === $user->id;
    }

    /** Revoke an existing invite the user owns (via its event). */
    public function delete(User $user, EventInvite $invite): bool
    {
        return $invite->event->user_id === $user->id;
    }
}
