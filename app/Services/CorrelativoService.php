<?php

namespace App\Services;

use App\Models\Correlativo;
use Illuminate\Support\Facades\DB;

class CorrelativoService
{
    /**
     * Devuelve el siguiente número del correlativo (ej. "C-000015", o "000015" sin prefijo como los tickets) y lo incrementa.
     * Usa bloqueo de fila: si se llama dentro de una transacción, el número
     * queda reservado hasta el commit y no se repite entre cajas.
     */
    public static function siguiente(string $tipo, string $prefijoPorDefecto = ''): string
    {
        return DB::transaction(function () use ($tipo, $prefijoPorDefecto) {
            $correlativo = Correlativo::where('tipo', $tipo)->lockForUpdate()->first();

            if (!$correlativo) {
                Correlativo::insertOrIgnore([
                    'tipo'       => $tipo,
                    'prefijo'    => $prefijoPorDefecto,
                    'siguiente'  => 1,
                    'relleno'    => 6,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $correlativo = Correlativo::where('tipo', $tipo)->lockForUpdate()->first();
            }

            $numero = $correlativo->siguiente;
            $correlativo->siguiente = $numero + 1;
            $correlativo->save();

            $texto = str_pad((string) $numero, $correlativo->relleno, '0', STR_PAD_LEFT);

            return $correlativo->prefijo !== '' ? $correlativo->prefijo . '-' . $texto : $texto;
        });
    }
}
