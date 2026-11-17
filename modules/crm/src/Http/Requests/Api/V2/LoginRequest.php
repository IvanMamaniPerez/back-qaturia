<?php

namespace VentureDrake\LaravelCrm\Http\Requests\Api\V2;

use Illuminate\Contracts\Validation\ValidationRule;

class LoginRequest extends IssueTokenRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [...parent::rules(), 'remember' => ['sometimes', 'boolean']];
    }
}
