<?php

namespace VentureDrake\LaravelCrm\Livewire\Auth;

use App\Services\AccountPasswordService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Mary\Traits\Toast;

#[Layout('laravel-crm::layouts.auth')]
class ForgotPassword extends Component
{
    use Toast;

    public string $email = '';

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'email' => 'required|email|max:255',
        ];
    }

    public function sendResetLink(AccountPasswordService $passwords): void
    {
        $this->validate();
        $passwords->sendResetLink($this->email);
        $this->email = '';
        $this->success('Si el correo está registrado, recibirás las instrucciones para recuperar tu contraseña.');
    }

    public function render(): View
    {
        return view('laravel-crm::livewire.auth.forgot-password');
    }
}
