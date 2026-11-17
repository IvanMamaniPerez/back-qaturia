<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PasswordStorageLimit implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && config('hashing.driver') === 'bcrypt'
            && (strlen($value) > 72 || str_contains($value, "\0"))) {
            $fail('La contraseña no puede superar 72 bytes ni contener caracteres nulos.');
        }
    }
}
