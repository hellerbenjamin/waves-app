<?php

namespace App\Http\Requests;

use App\Models\Media;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MediaUploadUrlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'filename' => ['required', 'string', 'max:255'],
            // S3/R2 reject a single PUT over 5 GB; larger files must go through
            // the multipart endpoints, which carry the higher (50 GB) cap.
            'size' => ['required', 'integer', 'min:1', 'max:5368709120'],
            'content_type' => ['required', 'string', Rule::in(Media::ALLOWED_MIMES)],
        ];
    }
}
