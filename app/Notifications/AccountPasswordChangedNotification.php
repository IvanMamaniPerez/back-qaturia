<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountPasswordChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Tu contraseña de QATUR IA fue actualizada')->greeting('Contraseña actualizada')->line('Se actualizó la contraseña de tu cuenta y se cerraron las sesiones anteriores. Si no reconoces este cambio, solicita una nueva recuperación de contraseña.');
    }
}
