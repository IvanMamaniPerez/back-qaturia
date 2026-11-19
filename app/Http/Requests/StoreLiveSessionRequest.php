<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLiveSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'clientSessionId' => ['required', 'uuid'],
            'platform' => ['required', 'string', 'in:tiktok'],
            'sourceRoomId' => ['required', 'string', 'max:128'],
            'creator' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'businessId' => ['prohibited'], 'team_id' => ['prohibited'],
        ];
    }
}
