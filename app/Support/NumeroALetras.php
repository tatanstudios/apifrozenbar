<?php

namespace App\Support;

/**
 * Montos en letras para el ticket: 6.05 → "Seis dólares Con Cinco Centavos".
 * La misma regla está en el frontend (utils/numeroALetras.ts) para el ticket del navegador.
 */
class NumeroALetras
{
    private const UNIDADES = ['', 'Uno', 'Dos', 'Tres', 'Cuatro', 'Cinco', 'Seis', 'Siete', 'Ocho', 'Nueve', 'Diez',
        'Once', 'Doce', 'Trece', 'Catorce', 'Quince', 'Dieciséis', 'Diecisiete', 'Dieciocho', 'Diecinueve', 'Veinte',
        'Veintiuno', 'Veintidós', 'Veintitrés', 'Veinticuatro', 'Veinticinco', 'Veintiséis', 'Veintisiete', 'Veintiocho', 'Veintinueve'];
    private const DECENAS = ['', '', '', 'Treinta', 'Cuarenta', 'Cincuenta', 'Sesenta', 'Setenta', 'Ochenta', 'Noventa'];
    private const CENTENAS = ['', 'Ciento', 'Doscientos', 'Trescientos', 'Cuatrocientos', 'Quinientos', 'Seiscientos', 'Setecientos', 'Ochocientos', 'Novecientos'];

    public static function dolares(float $monto): string
    {
        $centavosTotales = (int) round(abs($monto) * 100);
        $enteros = intdiv($centavosTotales, 100);
        $centavos = $centavosTotales % 100;

        $texto = $enteros === 0 ? 'Cero dólares' : self::apocopar(self::numero($enteros)) . ($enteros === 1 ? ' dólar' : ' dólares');
        return $texto . ' Con ' . ($centavos === 0 ? 'Cero' : self::apocopar(self::numero($centavos))) . ($centavos === 1 ? ' Centavo' : ' Centavos');
    }

    // "Uno" delante de dólares se dice "Un" (Veintiún, Treinta y Un, Un Mil...)
    private static function apocopar(string $texto): string
    {
        return preg_replace(['/Veintiuno$/u', '/Uno$/u'], ['Veintiún', 'Un'], $texto);
    }

    public static function numero(int $n): string
    {
        if ($n === 0) {
            return 'Cero';
        }
        if ($n >= 1000000) {
            $millones = intdiv($n, 1000000);
            $resto = $n % 1000000;
            $texto = $millones === 1 ? 'Un Millón' : self::apocopar(self::numero($millones)) . ' Millones';
            return $resto ? "{$texto} " . self::numero($resto) : $texto;
        }
        if ($n >= 1000) {
            $miles = intdiv($n, 1000);
            $resto = $n % 1000;
            $texto = $miles === 1 ? 'Mil' : self::apocopar(self::numero($miles)) . ' Mil';
            return $resto ? "{$texto} " . self::numero($resto) : $texto;
        }
        if ($n >= 100) {
            if ($n === 100) {
                return 'Cien';
            }
            $resto = $n % 100;
            return self::CENTENAS[intdiv($n, 100)] . ($resto ? ' ' . self::numero($resto) : '');
        }
        if ($n < 30) {
            return self::UNIDADES[$n];
        }
        $unidad = $n % 10;
        return self::DECENAS[intdiv($n, 10)] . ($unidad ? ' y ' . self::UNIDADES[$unidad] : '');
    }
}
