<?php

namespace App\Support\Impresion;

use App\Models\Impresora;

/**
 * Impresora térmica de red (Ethernet o Wi-Fi): los bytes se envían directo
 * al puerto RAW de la impresora, normalmente el 9100.
 */
class TransporteRed implements Transporte
{
    private const TIEMPO_ESPERA = 3; // segundos

    public function enviar(Impresora $impresora, string $bytes): void
    {
        if (!$impresora->ip_impresora) {
            throw new ImpresionException("{$impresora->alias} no tiene IP configurada");
        }

        $destino = "{$impresora->ip_impresora}:{$impresora->puerto}";
        $socket = @fsockopen($impresora->ip_impresora, (int) $impresora->puerto, $codigo, $mensaje, self::TIEMPO_ESPERA);

        if (!$socket) {
            throw new ImpresionException("No se pudo conectar con {$impresora->alias} ({$destino}): revisa que esté encendida y en la red");
        }

        stream_set_timeout($socket, self::TIEMPO_ESPERA);
        $escrito = @fwrite($socket, $bytes);

        // Las Star descartan el trabajo si la conexión se cierra antes de que
        // terminen de recibirlo: se espera un momento (lo que mandan es su estado)
        if ($impresora->tipo_impresion === 'star_raster') {
            stream_set_timeout($socket, 1);
            $hasta = microtime(true) + 1;
            while (microtime(true) < $hasta && !feof($socket) && @fread($socket, 64) !== false) {
            }
        }
        fclose($socket);

        if ($escrito === false || $escrito < strlen($bytes)) {
            throw new ImpresionException("La conexión con {$impresora->alias} ({$destino}) se cortó al imprimir");
        }
    }
}
