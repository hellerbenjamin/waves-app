<?php

namespace App\Http\Requests;

use App\Models\EventInvite;
use App\Models\Media;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Finalises an anonymous contribution. Authorisation is the invite token in the
 * URL (resolved to an {@see EventInvite} by the route binder), so there is no
 * user() to check — the key must instead sit under this event's contrib prefix.
 */
class StoreContributionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var EventInvite $invite */
        $invite = $this->route('invite');

        return [
            's3_key' => ['required', 'string', 'starts_with:media/events/'.$invite->event_id.'/'],
            'original_name' => ['required', 'string', 'max:255'],
            'mime' => ['required', 'string', Rule::in(Media::ALLOWED_MIMES)],
            'size' => ['required', 'integer', 'min:1'],
            'contributor_name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
