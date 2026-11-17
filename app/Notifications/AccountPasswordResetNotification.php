<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class AccountPasswordResetNotification extends ResetPassword implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(string $token)
    {
        parent::__construct($token);
        $this->afterCommit();
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $url = rtrim(config('auth.public_url'), '/').route('laravel-crm.password.reset', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()], false);
        $minutes = config('auth.passwords.users.expire');

        return (new MailMessage)->subject('Recupera tu contraseña de QATUR IA')
            ->greeting('Recuperación de contraseña')
            ->line('Solicitaste restablecer la contraseña de tu cuenta.')
            ->action('Restablecer contraseña', $url)
            ->line('También puedes copiar este código en la aplicación Android o desktop:')
            ->line($this->token)
            ->line("El enlace y el código vencen en {$minutes} minutos y se pueden utilizar una sola vez.")
            ->line('Si no solicitaste este cambio, puedes ignorar este correo.');
    }
}
