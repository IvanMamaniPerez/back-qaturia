<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\RegisterAccountRequest;
use App\Services\ApiTokenSessionService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use VentureDrake\LaravelCrm\Http\Controllers\Api\V2\AuthController;
use VentureDrake\LaravelCrm\Services\BusinessRegistrationService;

class RegistrationController extends AuthController
{
    public function store(RegisterAccountRequest $request, BusinessRegistrationService $registration, ApiTokenSessionService $sessions): JsonResponse
    {
        try {
            $credentials = DB::transaction(function () use ($request, $registration, $sessions): array {
                $user = $registration->register($request->safe()->only(['name', 'email', 'password', 'business_name', 'legal_name', 'tax_id']));

                return $sessions->issue($user, $request->validated('device_name') ?? 'Qaturia');
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'Este correo ya está registrado.']);
        }
        event(new Registered($credentials['user']));

        return response()->json([...$credentials, 'user' => $this->transformUser($credentials['user'])], 201)
            ->header('Cache-Control', 'no-store, private');
    }
}
