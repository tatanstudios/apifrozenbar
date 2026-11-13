<?php

namespace App\Services;

use App\Exceptions\AutorizacionRequeridaException;
use App\Models\Administrador;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AutorizacionService
{
    private const INTENTOS = 5;

    /** PIN o contraseña incorrectos seguidos para entrar o desbloquear antes de esperar un minuto. */
    public const INTENTOS_ENTRAR = 25;

    /** Nombre del token que registra una tablet de confianza (para entrar con PIN en el inicio de sesión). */
    public const LLAVE_TABLET = 'tablet-dispositivo';

    /**
     * Devuelve quién autoriza una operación que exige $permiso:
     * - el propio usuario si tiene el permiso;
     * - si no, quien tenga el permiso y cuyo PIN o contraseña venga en el campo
     *   "autorizacion" de la petición (el administrador lo escribe en la tablet
     *   del mesero sin darle su contraseña).
     * Si no hay nada de eso, la operación no se hace: 403 SIN_PERMISO.
     */
    public static function autorizar(Administrador $usuario, string $permiso, string $mensaje): Administrador
    {
        if ($usuario->can($permiso)) {
            return $usuario;
        }

        $codigo = trim((string) request()->input('autorizacion', ''));
        if ($codigo === '') {
            throw new AutorizacionRequeridaException($permiso, $mensaje);
        }

        $clave = 'autorizacion:' . $usuario->id;
        if (RateLimiter::tooManyAttempts($clave, self::INTENTOS)) {
            $segundos = RateLimiter::availableIn($clave);
            throw new AutorizacionRequeridaException($permiso, "Demasiados intentos con PIN incorrecto. Espera {$segundos} segundos", true);
        }

        $autorizador = self::buscar($codigo, $permiso);
        if (!$autorizador) {
            RateLimiter::hit($clave, 60);
            throw new AutorizacionRequeridaException($permiso, 'PIN o contraseña incorrecto, o esa persona no puede autorizar esto', true);
        }

        RateLimiter::clear($clave);
        return $autorizador;
    }

    /** Quien tiene el permiso y ese PIN (primero) o esa contraseña. */
    private static function buscar(string $codigo, string $permiso): ?Administrador
    {
        $candidatos = Administrador::where('estado', true)->get()->filter(fn (Administrador $a) => $a->can($permiso));

        return $candidatos->first(fn (Administrador $a) => $a->pin && Hash::check($codigo, $a->pin))
            ?? $candidatos->first(fn (Administrador $a) => Hash::check($codigo, $a->password));
    }

    /** El usuario activo dueño de ese PIN (no se repite entre usuarios), o null. */
    public static function usuarioPorPin(string $pin): ?Administrador
    {
        return Administrador::where('estado', true)->whereNotNull('pin')->get()
            ->first(fn (Administrador $a) => Hash::check($pin, $a->pin));
    }

    /** ¿Otro usuario ya usa ese PIN? El PIN identifica a quien autoriza, no se puede repetir. */
    public static function pinEnUso(string $pin, int $excepto): bool
    {
        return Administrador::whereNotNull('pin')->where('id', '!=', $excepto)->get(['id', 'pin'])
            ->contains(fn (Administrador $a) => Hash::check($pin, $a->pin));
    }
}
