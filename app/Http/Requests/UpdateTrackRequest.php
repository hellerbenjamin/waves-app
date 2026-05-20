<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'channel_labels' => ['present', 'array', 'max:'.max($channels, 1)],
            'channel_labels.*' => ['nullable', 'string', 'max:60'],
        ];
    }
}
