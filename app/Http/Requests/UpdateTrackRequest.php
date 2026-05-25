<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTrackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('track')) ?? false;
    }

    public function rules(): array
    {
        $channels = count($this->route('track')->peaks['channels'] ?? []);

        return [
            'original_name' => ['sometimes', 'required', 'string', 'max:255'],
            'channel_labels' => ['sometimes', 'array', 'max:'.max($channels, 1)],
            'channel_labels.*' => ['nullable', 'string', 'max:60'],
            // null moves the track out of any event; an id must be an event the
            // user owns.
            'event_id' => ['sometimes', 'nullable', 'integer', Rule::exists('events', 'id')->where('user_id', $this->user()->id)],
        ];
    }
}
