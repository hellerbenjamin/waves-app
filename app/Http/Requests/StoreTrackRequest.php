<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Finalises a browser-encoded multi-channel upload. Each channel's Opus and
 * peaks objects are already in the bucket (uploaded against the keys minted by
 * the channel-init endpoint); this manifest references them by key. Keys must
 * carry the owner prefix — that's the only authorisation the finalise needs,
 * since no Track row exists yet.
 */
class StoreTrackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $prefix = 'users/'.$this->user()->id.'/';

        return [
            'original_name' => ['required', 'string', 'max:255'],
            'event_id' => ['nullable', 'integer', Rule::exists('events', 'id')->where('user_id', $this->user()->id)],
            'duration_seconds' => ['required', 'numeric', 'min:0'],
            'sample_rate' => ['required', 'integer', 'min:1'],
            'channels' => ['required', 'array', 'min:1', 'max:64'],
            'channels.*.index' => ['required', 'integer', 'min:0'],
            'channels.*.opus_key' => ['required', 'string', 'starts_with:'.$prefix],
            'channels.*.peaks_key' => ['required', 'string', 'starts_with:'.$prefix],
            'channels.*.label' => ['nullable', 'string', 'max:120'],
            'channels.*.size' => ['required', 'integer', 'min:1'],
        ];
    }
}
