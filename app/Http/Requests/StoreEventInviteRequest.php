<?php

namespace App\Http\Requests;

use App\Models\Event;
use App\Models\EventInvite;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Owner-side: mint a contribution link for an event the user owns.
 */
class StoreEventInviteRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Event $event */
        $event = $this->route('event');

        return $this->user()?->can('create', [EventInvite::class, $event]) ?? false;
    }

    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:80'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
