<?php

namespace App\Http\Requests;

use App\Models\Media;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            's3_key' => ['required', 'string', 'starts_with:media/users/'.$this->user()->id.'/'],
            'original_name' => ['required', 'string', 'max:255'],
            'mime' => ['required', 'string', Rule::in(Media::ALLOWED_MIMES)],
            'size' => ['required', 'integer', 'min:1'],
            // Optional: drop the upload straight into an event the user owns.
            'event_id' => ['nullable', 'integer', Rule::exists('events', 'id')->where('user_id', $this->user()->id)],
        ];
    }
}
