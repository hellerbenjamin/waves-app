<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadUrlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'filename' => ['required', 'string', 'max:255', 'regex:/\.wav$/i'],
            'size' => ['required', 'integer', 'min:1', 'max:5368709120'],
            'content_type' => ['required', 'string', 'in:audio/wav,audio/x-wav,audio/wave,audio/vnd.wave'],
        ];
    }
}
