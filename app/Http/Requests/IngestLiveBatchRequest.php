<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IngestLiveBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'batchId' => ['required', 'uuid'],
            'contractVersion' => ['required', 'integer', 'in:1'],
            'liveSessionId' => ['required', 'uuid'],
            'events' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'businessId' => ['prohibited'], 'team_id' => ['prohibited'],
        ];
    }
}
