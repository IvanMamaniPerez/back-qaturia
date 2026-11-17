<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetAccountPasswordRequest;
use App\Services\AccountPasswordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function request(ForgotPasswordRequest $request, AccountPasswordService $passwords): JsonResponse
    {
        $passwords->sendResetLink($request->validated('email'));

        return response()->json(['message' => 'Si el correo está registrado, recibirás las instrucciones para recuperar tu contraseña.'], 202)
            ->header('Cache-Control', 'no-store, private');
    }

    public function reset(ResetAccountPasswordRequest $request, AccountPasswordService $passwords): JsonResponse
    {
        $status = $passwords->reset(['email' => $request->string('email')->toString(), 'token' => $request->string('token')->toString(), 'password' => $request->string('password')->toString(), 'password_confirmation' => $request->string('password_confirmation')->toString()]);
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => 'El enlace o código no es válido o ha vencido. Solicita uno nuevo.']);
        }

        return response()->json(['message' => 'Tu contraseña fue actualizada. Inicia sesión con la nueva contraseña.'])
            ->header('Cache-Control', 'no-store, private');
    }
}
