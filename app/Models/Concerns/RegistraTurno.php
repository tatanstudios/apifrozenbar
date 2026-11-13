<?php

namespace App\Models\Concerns;

use App\Models\TurnoCaja;

/**
 * Guarda en qué turno de caja se hizo el registro (además de quién lo hizo), para
 * saber en reportes a qué turno pertenece: quién lo abrió, quién lo cerró y cuándo.
 * Si el registro ya trae su turno (una venta, un gasto pagado de la caja), se respeta.
 */
trait RegistraTurno
{
    public static function bootRegistraTurno(): void
    {
        static::creating(function ($modelo) {
            if (empty($modelo->turno_id)) {
                $modelo->turno_id = TurnoCaja::idEnCurso($modelo->usuario_id ?? auth()->id());
            }
        });
    }

    public function turno()
    {
        return $this->belongsTo(TurnoCaja::class, 'turno_id');
    }
}
