<?php

namespace VentureDrake\LaravelCrm\Livewire\Auth;

use App\Rules\PasswordStorageLimit;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use VentureDrake\LaravelCrm\Services\BusinessRegistrationService;

#[Layout('laravel-crm::layouts.auth')]
class Register extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $business_name = '';

    public ?string $legal_name = null;

    public ?string $tax_id = null;

    public function register(BusinessRegistrationService $service): void
    {
        abort_unless(config('laravel-crm.teams'), 404);
        abort_unless(config('auth.registration_enabled', true), 403);
        $data = $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => ['required', 'string', 'confirmed', new PasswordStorageLimit, Password::defaults()],
            'business_name' => 'required|string|max:255',
            'legal_name' => 'nullable|string|max:255',
            'tax_id' => 'nullable|string|max:32',
        ]);
        try {
            $user = $service->register($data);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'This email address is already registered.']);
        }
        event(new Registered($user));
        Auth::login($user);
        session()->regenerate();
        $this->redirect(route('laravel-crm.dashboard'));
    }

    public function render(): View
    {
        return view('laravel-crm::livewire.auth.register');
    }
}
