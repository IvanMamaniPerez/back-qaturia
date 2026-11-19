<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLiveConnectorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'connectorInstanceId' => ['required', 'uuid'],
            'connectorKind' => ['required', 'string', 'in:desktop,android'],
            'deviceId' => ['nullable', 'uuid'],
            'businessId' => ['prohibited'], 'team_id' => ['prohibited'],
        ];
    }
}
