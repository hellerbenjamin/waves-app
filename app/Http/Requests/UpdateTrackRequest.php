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
        $channels = (int) $this->route('track')->channels_count;

        return [
            'original_name' => ['sometimes', 'required', 'string', 'max:255'],
            'channel_labels' => ['sometimes', 'array', 'max:'.max($channels, 1)],
            'channel_labels.*' => ['nullable', 'string', 'max:60'],
            // Saved mixer state per channel. Sent as null to clear, or an
            // array of {level, pan, muted}; entries past the channel count
            // are trimmed in the controller rather than rejected.
            'default_mix' => ['sometimes', 'nullable', 'array'],
            'default_mix.*.level' => ['required_with:default_mix.*', 'numeric', 'between:0,100'],
            'default_mix.*.pan' => ['required_with:default_mix.*', 'numeric', 'between:-100,100'],
            'default_mix.*.muted' => ['required_with:default_mix.*', 'boolean'],
            'default_mix.*.solo' => ['sometimes', 'boolean'],
            // Per-channel preamp trim in dB. Snapped to 5 dB steps in [0, 20]
            // in the controller; we accept any number in range here.
            'default_mix.*.boost' => ['sometimes', 'numeric', 'between:0,20'],
            // null moves the track out of any event; an id must be an event the
            // user owns.
            'event_id' => ['sometimes', 'nullable', 'integer', Rule::exists('events', 'id')->where('user_id', $this->user()->id)],
        ];
    }
}
