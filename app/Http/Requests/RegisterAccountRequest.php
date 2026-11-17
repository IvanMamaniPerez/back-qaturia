<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Rules\PasswordStorageLimit;
use Closure;
use Illuminate\Validation\Rules\Password;

class RegisterAccountRequest extends ForgotPasswordRequest
{
    public function authorize(): bool
    {
        return (bool) config('auth.registration_enabled', true) && (bool) config('laravel-crm.teams');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'name' => ['required', 'string', 'max:255'],
            'business_name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:32'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'email' => [...parent::rules()['email'], function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && User::withoutGlobalScopes()->whereRaw('LOWER(email) = ?', [$value])->exists()) {
                    $fail('Este correo ya está registrado.');
                }
            }],
            'password' => ['required', 'string', 'confirmed', new PasswordStorageLimit, Password::defaults()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['password.confirmed' => 'Las contraseñas no coinciden.'];
    }
}
