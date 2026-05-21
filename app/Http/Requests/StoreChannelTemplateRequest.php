<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreChannelTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'labels' => ['present', 'array', 'max:64'],
            'labels.*' => ['nullable', 'string', 'max:60'],
        ];
    }
}
