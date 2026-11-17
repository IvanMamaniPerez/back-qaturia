<?php

namespace VentureDrake\LaravelCrm\Livewire\Auth;

use App\Rules\PasswordStorageLimit;
use App\Services\AccountPasswordService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Mary\Traits\Toast;

#[Layout('laravel-crm::layouts.auth')]
class ResetPassword extends Component
{
    use Toast;

    public string $token = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token, ?string $email = null): void
    {
        $this->token = $token;
        $this->email = $email ?? request()->query('email', '');
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'string', 'confirmed', new PasswordStorageLimit, PasswordRule::defaults()],
        ];
    }

    public function resetPassword(AccountPasswordService $passwords): void
    {
        $data = $this->validate();
        $status = $passwords->reset($data);

        if ($status === Password::PASSWORD_RESET) {
            session()->flash('status', ucfirst(trans('laravel-crm::lang.password_reset_success')));

            $this->redirect(route('laravel-crm.login'));

            return;
        }

        $this->error(
            trans($status)
        );
    }

    public function render(): View
    {
        return view('laravel-crm::livewire.auth.reset-password');
    }
}
