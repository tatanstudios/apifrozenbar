<?php

namespace App\Notifications;

use App\Models\ConfiguracionNegocio;
use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Storage;

class ResetPasswordAdministrador extends BaseResetPassword
{
    public function toMail($notifiable)
    {

        $resetUrl = config('app.frontend_url')
            . '/admin/reset-password'
            . '?token=' . $this->token
            . '&email=' . urlencode($notifiable->email);

        return (new MailMessage)
            ->subject('Recuperar contraseña - Administración')
            ->view('emails.reset-password-admin', [
                'nombre'   => $notifiable->nombre,
                'email'    => $notifiable->email,
                'resetUrl' => $resetUrl,
                'logoUrl'  => $this->logoUrl(),
            ]);
    }

    /**
     * URL pública del logo para el correo: el logo configurado del negocio y, si no hay,
     * el logo por defecto de la web (images/logo/logoe.jpg, mismo dominio que la API).
     */
    private function logoUrl(): string
    {
        $logo = ConfiguracionNegocio::query()->value('logo');

        if ($logo && Storage::disk('archivos')->exists($logo)) {
            return Storage::disk('archivos')->url($logo);
        }

        return asset('images/logo/logoe.jpg');
    }
}
