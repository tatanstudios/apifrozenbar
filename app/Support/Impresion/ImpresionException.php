<?php

namespace App\Support\Impresion;

/**
 * La impresora no respondió o rechazó el trabajo. El mensaje se muestra al
 * usuario (ej. "No se pudo conectar con 192.168.1.50:9100").
 */
class ImpresionException extends \RuntimeException
{
}
