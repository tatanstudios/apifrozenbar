<?php

namespace App\Support\Impresion;

use App\Models\Impresora;

/**
 * Envía bytes ESC/POS a una impresora. Lanza ImpresionException si falla.
 */
interface Transporte
{
    public function enviar(Impresora $impresora, string $bytes): void;
}
