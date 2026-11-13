<?php

namespace App\Services;

use App\Models\Auditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class AuditoriaService
{
    /**
     * Registra una operación en la bitácora de auditoría.
     * Nunca interrumpe la operación principal: si falla, solo se escribe en el log.
     *
     * @param string      $modulo   auth|inventario|productos|usuarios|roles|configuracion|...
     * @param string      $accion   login|crear|actualizar|anular|ajustar|...
     * @param Model|null  $entidad  registro afectado (se guarda tipo e id)
     */
    public static function registrar(
        string  $modulo,
        string  $accion,
        ?Model  $entidad = null,
        ?array  $antes = null,
        ?array  $despues = null,
        ?string $descripcion = null,
        ?int    $usuarioId = null,
    ): ?Auditoria {
        try {
            $request = request();

            return Auditoria::create([
                'usuario_id'    => $usuarioId ?? auth('sanctum')->id(),
                'modulo'        => $modulo,
                'accion'        => $accion,
                'entidad_tipo'  => $entidad ? class_basename($entidad) : null,
                'entidad_id'    => $entidad?->getKey(),
                'descripcion'   => $descripcion ? mb_substr($descripcion, 0, 500) : null,
                'datos_antes'   => $antes ? self::limpiar($antes) : null,
                'datos_despues' => $despues ? self::limpiar($despues) : null,
                'ip'            => $request?->ip(),
                'user_agent'    => $request ? mb_substr((string) $request->userAgent(), 0, 300) : null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error al registrar auditoría: ' . $e->getMessage());
            return null;
        }
    }

    // Quita datos sensibles antes de guardarlos
    private static function limpiar(array $datos): array
    {
        return collect($datos)
            ->except(['password', 'remember_token', 'password_confirmation', 'pin', 'pin_confirmation', 'autorizacion'])
            ->toArray();
    }
}
