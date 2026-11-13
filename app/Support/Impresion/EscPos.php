<?php

namespace App\Support\Impresion;

/**
 * Constructor de comandos ESC/POS para impresoras térmicas.
 * Arma el contenido en memoria; el envío lo hace un Transporte.
 *
 * Los acentos y la ñ se convierten a la página de códigos CP850, que
 * soportan casi todas las impresoras térmicas (Epson, Star, Xprinter, 3nStar...).
 */
class EscPos
{
    private const ESC = "\x1B";
    private const GS  = "\x1D";

    private string $datos = '';

    public function __construct(private readonly int $ancho = 48)
    {
        // Inicializar y seleccionar página de códigos CP850 (n = 2)
        $this->datos = self::ESC . '@' . self::ESC . 't' . "\x02";
    }

    public function ancho(): int
    {
        return $this->ancho;
    }

    public function alinear(string $alineacion): self
    {
        $n = match ($alineacion) {
            'centro'  => 1,
            'derecha' => 2,
            default   => 0,
        };
        $this->datos .= self::ESC . 'a' . chr($n);
        return $this;
    }

    public function negrita(bool $activa = true): self
    {
        $this->datos .= self::ESC . 'E' . chr($activa ? 1 : 0);
        return $this;
    }

    // Doble alto y/o doble ancho (para totales y títulos)
    public function grande(bool $dobleAlto = true, bool $dobleAncho = true): self
    {
        $n = ($dobleAncho ? 0x20 : 0) | ($dobleAlto ? 0x10 : 0);
        $this->datos .= self::GS . '!' . chr($n);
        return $this;
    }

    public function normal(): self
    {
        $this->datos .= self::GS . '!' . "\x00" . self::ESC . 'E' . "\x00";
        return $this;
    }

    public function texto(string $texto): self
    {
        $this->datos .= self::codificar($texto);
        return $this;
    }

    public function linea(string $texto = ''): self
    {
        return $this->texto($texto . "\n");
    }

    // Texto a la izquierda y valor a la derecha en la misma línea ("Total ..... $5.00")
    public function columnas(string $izquierda, string $derecha, ?int $ancho = null): self
    {
        $ancho ??= $this->ancho;
        $derecha = mb_substr($derecha, 0, $ancho);
        // Siempre queda al menos un espacio entre ambas columnas
        $izquierda = mb_substr($izquierda, 0, max($ancho - mb_strlen($derecha) - 1, 0));
        $espacios = $ancho - mb_strlen($izquierda) - mb_strlen($derecha);

        return $this->linea($izquierda . str_repeat(' ', max($espacios, 1)) . $derecha);
    }

    // Texto largo partido en varias líneas sin cortar palabras
    public function parrafo(string $texto, ?int $ancho = null): self
    {
        foreach (explode("\n", wordwrap($texto, $ancho ?? $this->ancho, "\n", true)) as $linea) {
            $this->linea($linea);
        }
        return $this;
    }

    public function separador(string $caracter = '-'): self
    {
        return $this->linea(str_repeat($caracter, $this->ancho));
    }

    public function avanzar(int $lineas = 1): self
    {
        $this->datos .= self::ESC . 'd' . chr(max(0, min($lineas, 255)));
        return $this;
    }

    // Corte parcial del papel (deja una pestaña para arrancarlo)
    public function cortar(): self
    {
        $this->avanzar(3);
        $this->datos .= self::GS . 'V' . "\x42" . "\x00";
        return $this;
    }

    /**
     * Pulso para abrir el cajón de dinero conectado a la impresora.
     * $pin: 'pin2' (conector 2) o 'pin5' (conector 5).
     */
    public function abrirCajon(string $pin = 'pin2'): self
    {
        $this->datos .= self::ESC . 'p' . ($pin === 'pin5' ? "\x01" : "\x00") . "\x19" . "\xFA";
        return $this;
    }

    /**
     * Marca para StarRaster: dibujar este documento a sus propias columnas (letra más grande
     * si son menos que las de la impresora). Solo se usa en documentos para impresoras Star,
     * que reciben imagen; no es un comando ESC/POS.
     */
    public function anchoRaster(): self
    {
        $this->datos .= self::ESC . '~' . chr($this->ancho);
        return $this;
    }

    public function bytes(): string
    {
        return $this->datos;
    }

    public static function codificar(string $texto): string
    {
        // Caracteres que CP850 no tiene se reemplazan para no imprimir basura
        $texto = strtr($texto, ['“' => '"', '”' => '"', '‘' => "'", '’' => "'", '–' => '-', '—' => '-', '…' => '...', '−' => '-', '•' => '*', '€' => 'EUR']);

        return mb_convert_encoding($texto, 'CP850', 'UTF-8');
    }
}
