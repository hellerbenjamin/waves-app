<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTrackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            's3_key' => ['required', 'string', 'starts_with:users/'.$this->user()->id.'/'],
            'original_name' => ['required', 'string', 'max:255'],
            'mime' => ['required', 'string', 'max:64'],
            'size' => ['required', 'integer', 'min:1'],
        ];
    }
}
