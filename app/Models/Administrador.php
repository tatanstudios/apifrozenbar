<?php

namespace App\Models;

use App\Notifications\ResetPasswordAdministrador;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Auth\Passwords\CanResetPassword as ResetPasswordTrait;
use Spatie\Permission\Traits\HasRoles;

class Administrador extends Authenticatable
{
    use HasApiTokens, Notifiable, ResetPasswordTrait, HasRoles;
    protected $table = 'administradores'; // Asegúrate de tener el nombre correcto de la tabla

    protected $guard_name = 'api';

    protected $fillable = [
        'nombre',
        'email',
        'password',
        'estado',
        'ultimo_acceso',
        'pin',
        'pin_actualizado_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'pin',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'estado' => 'boolean',
        'ultimo_acceso' => 'datetime',
        'pin' => 'hashed',
        'pin_actualizado_at' => 'datetime',
    ];

    public function sendPasswordResetNotification($token)
    {
        $this->notify(new ResetPasswordAdministrador($token));
    }


}
