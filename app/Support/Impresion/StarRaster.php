<?php

namespace App\Support\Impresion;

/**
 * Convierte un documento ESC/POS (el que arma EscPos) a comandos raster de Star
 * (Star Graphic Mode). Las Star TSP100 / TSP143 no tienen fuentes: solo imprimen
 * imágenes, así que el ticket se dibuja línea por línea con una fuente monoespaciada
 * y se envía como mapa de bits. Las plantillas siguen siendo las mismas.
 */
class StarRaster
{
    private const ALTO_LINEA = 2.0;   // alto de la línea respecto al ancho de un carácter (12 x 24)
    private const MARGEN_FINAL = 24;  // puntos en blanco antes del corte

    public function __construct(private readonly int $ancho = 48)
    {
    }

    public static function desdeEscPos(string $bytes, int $ancho = 48): string
    {
        return (new self($ancho))->convertir($bytes);
    }

    public function convertir(string $bytes): string
    {
        // 80 mm = 576 puntos imprimibles, 58 mm = 384
        $puntos = $this->ancho >= 42 ? 576 : 384;
        // El documento puede pedir sus propias columnas (ESC ~ n, ver EscPos::anchoRaster): letra más grande
        $columnas = preg_match('/\x1B~(.)/s', $bytes, $m) && ord($m[1]) >= 16 ? min(ord($m[1]), $this->ancho) : $this->ancho;
        $celda = $puntos / $columnas;
        $altoLinea = (int) round($celda * self::ALTO_LINEA);

        [$lineas, $cortar, $cajon] = $this->interpretar($bytes, $altoLinea);

        $alto = max(1, array_sum(array_column($lineas, 'alto')) + self::MARGEN_FINAL);
        $imagen = imagecreatetruecolor($puntos, $alto);
        imagefill($imagen, 0, 0, imagecolorallocate($imagen, 255, 255, 255));

        $y = 0;
        foreach ($lineas as $l) {
            if ($l['texto'] !== '') {
                $this->dibujar($imagen, $l, $y, $celda, $altoLinea, $puntos);
            }
            $y += $l['alto'];
        }

        $salida = "\x1B@"             // reiniciar
            . "\x1B*rR"               // inicializar modo raster
            . "\x1B*rA"               // entrar a modo raster
            . "\x1B*rP0\x00";         // largo de página continuo
        if (!$cortar) {
            $salida .= "\x1B*rE1\x00"; // terminar sin cortar
        }
        $salida .= $this->filas($imagen, $puntos, $alto)
            . "\x1B\x0C\x00"          // fin de página (avanza y corta)
            . "\x1B*rB";              // salir de modo raster

        // Cajón: BEL abre el periférico 1, SUB el 2
        if ($cajon) {
            $salida .= $cajon === 'pin5' ? "\x1A" : "\x07";
        }

        imagedestroy($imagen);

        return $salida;
    }

    /**
     * Recorre el subconjunto de ESC/POS que genera EscPos y devuelve las líneas con
     * su formato. El formato que vale es el que está activo al terminar la línea.
     */
    private function interpretar(string $bytes, int $altoLinea): array
    {
        $lineas = [];
        $texto = '';
        $estado = ['alinear' => 0, 'negrita' => false, 'dobleAncho' => 1, 'dobleAlto' => 1];
        $cortar = false;
        $cajon = null;
        $n = strlen($bytes);

        for ($i = 0; $i < $n; $i++) {
            $c = $bytes[$i];

            if ($c === "\x1B" && $i + 1 < $n) {
                $cmd = $bytes[$i + 1];
                $arg = ord($bytes[$i + 2] ?? "\x00");
                switch ($cmd) {
                    case '@': $estado = ['alinear' => 0, 'negrita' => false, 'dobleAncho' => 1, 'dobleAlto' => 1]; $i += 1; break;
                    case 't': $i += 2; break;
                    case '~': $i += 2; break; // columnas del documento (ya leídas)
                    case 'a': $estado['alinear'] = $arg; $i += 2; break;
                    case 'E': $estado['negrita'] = $arg === 1; $i += 2; break;
                    case 'd':
                        for ($k = 0; $k < $arg; $k++) {
                            $lineas[] = ['texto' => '', 'alto' => $altoLinea] + $estado;
                        }
                        $i += 2;
                        break;
                    case 'p': $cajon = $arg === 1 ? 'pin5' : 'pin2'; $i += 4; break;
                    default: $i += 1;
                }
                continue;
            }

            if ($c === "\x1D" && $i + 1 < $n) {
                $cmd = $bytes[$i + 1];
                $arg = ord($bytes[$i + 2] ?? "\x00");
                if ($cmd === '!') {
                    $estado['dobleAncho'] = ($arg & 0x20) ? 2 : 1;
                    $estado['dobleAlto'] = ($arg & 0x10) ? 2 : 1;
                    $i += 2;
                } elseif ($cmd === 'V') {
                    $cortar = true;
                    $i += 3;
                } else {
                    $i += 1;
                }
                continue;
            }

            if ($c === "\n") {
                $lineas[] = ['texto' => mb_convert_encoding($texto, 'UTF-8', 'CP850'), 'alto' => $altoLinea * $estado['dobleAlto']] + $estado;
                $texto = '';
                continue;
            }

            $texto .= $c;
        }

        if ($texto !== '') {
            $lineas[] = ['texto' => mb_convert_encoding($texto, 'UTF-8', 'CP850'), 'alto' => $altoLinea * $estado['dobleAlto']] + $estado;
        }

        // El avance antes del corte sobra: la impresora avanza sola hasta la cuchilla
        while ($lineas && trim(end($lineas)['texto']) === '') {
            array_pop($lineas);
        }

        return [$lineas, $cortar, $cajon];
    }

    // Dibuja la línea a tamaño normal y la escala si es de doble ancho/alto
    private function dibujar(\GdImage $destino, array $l, int $y, float $celda, int $altoLinea, int $puntos): void
    {
        $fuente = self::fuente($l['negrita']);
        $largo = mb_strlen($l['texto']);
        $anchoNormal = (int) ceil($largo * $celda);

        $linea = imagecreatetruecolor(max(1, $anchoNormal), $altoLinea);
        imagefill($linea, 0, 0, imagecolorallocate($linea, 255, 255, 255));
        $negro = imagecolorallocate($linea, 0, 0, 0);

        // Tamaño de letra para que cada carácter ocupe exactamente una celda
        $tamano = self::tamanoPara($fuente, $celda);
        $base = (int) round($altoLinea * 0.78);
        foreach (mb_str_split($l['texto']) as $k => $caracter) {
            imagettftext($linea, $tamano, 0, (int) round($k * $celda), $base, $negro, $fuente, $caracter);
        }

        $anchoFinal = min($anchoNormal * $l['dobleAncho'], $puntos);
        $altoFinal = $altoLinea * $l['dobleAlto'];
        $x = match ($l['alinear']) {
            1 => intdiv($puntos - $anchoFinal, 2),
            2 => $puntos - $anchoFinal,
            default => 0,
        };

        imagecopyresampled($destino, $linea, $x, $y, 0, 0, $anchoFinal, $altoFinal,
            (int) round($anchoFinal / $l['dobleAncho']), $altoLinea);
        imagedestroy($linea);
    }

    // Cada fila es "b n1 n2" + los bytes de la fila (1 = punto negro, bit alto a la izquierda)
    private function filas(\GdImage $imagen, int $puntos, int $alto): string
    {
        $porFila = intdiv($puntos, 8);
        $salida = '';

        for ($y = 0; $y < $alto; $y++) {
            $fila = '';
            for ($b = 0; $b < $porFila; $b++) {
                $byte = 0;
                for ($bit = 0; $bit < 8; $bit++) {
                    $rgb = imagecolorat($imagen, $b * 8 + $bit, $y);
                    if ((($rgb >> 16) & 0xFF) < 128) {
                        $byte |= 0x80 >> $bit;
                    }
                }
                $fila .= chr($byte);
            }
            $salida .= 'b' . chr($porFila & 0xFF) . chr($porFila >> 8) . $fila;
        }

        return $salida;
    }

    private static function fuente(bool $negrita): string
    {
        $archivo = $negrita ? 'consolab.ttf' : 'consola.ttf';
        foreach ([resource_path("fonts/{$archivo}"), "C:\\Windows\\Fonts\\{$archivo}"] as $ruta) {
            if (is_file($ruta)) {
                return $ruta;
            }
        }

        throw new ImpresionException("No se encontró la fuente {$archivo} para imprimir en la Star (cópiala a resources/fonts)");
    }

    private static function tamanoPara(string $fuente, float $celda): float
    {
        static $cache = [];

        return $cache[$fuente][(string) $celda] ??= (function () use ($fuente, $celda) {
            $caja = imagettfbbox(20, 0, $fuente, str_repeat('M', 10));
            $anchoA20 = ($caja[2] - $caja[0]) / 10;

            return 20 * $celda / $anchoA20;
        })();
    }
}
