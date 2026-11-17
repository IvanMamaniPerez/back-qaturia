<?php

namespace App\Http\Requests;

use App\Rules\PasswordStorageLimit;
use Illuminate\Validation\Rules\Password;

class ResetAccountPasswordRequest extends ForgotPasswordRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'token' => ['required', 'string', 'regex:/^[a-fA-F0-9]{64}$/'],
            'password' => ['required', 'string', 'confirmed', new PasswordStorageLimit, Password::defaults()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['password.confirmed' => 'Las contraseñas no coinciden.', 'token.regex' => 'El código de recuperación no es válido.'];
    }
}
