<?php

namespace App\Support\Impresion;

use App\Models\Impresora;

/**
 * Impresora instalada en Windows (USB) en la computadora donde corre el servidor.
 * Envía los bytes en modo RAW con la API de impresión de Windows (winspool),
 * así funcionan el corte de papel y la apertura del cajón.
 */
class TransporteWindows implements Transporte
{
    public function enviar(Impresora $impresora, string $bytes): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new ImpresionException('La impresión por Windows solo funciona si el servidor es Windows');
        }
        if (!$impresora->nombre_windows) {
            throw new ImpresionException("{$impresora->alias} no tiene el nombre de la impresora de Windows");
        }

        $archivo = tempnam(sys_get_temp_dir(), 'tkt');
        $script  = $archivo . '.ps1';
        file_put_contents($archivo, $bytes);

        // El nombre va entre comillas simples de PowerShell: se escapan las que traiga
        $nombre = str_replace("'", "''", $impresora->nombre_windows);
        $ruta   = str_replace("'", "''", $archivo);

        file_put_contents($script, <<<PS
        \$ErrorActionPreference = 'Stop'
        Add-Type -TypeDefinition @"
        using System;
        using System.Runtime.InteropServices;
        public class ImpresoraRaw {
            [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
            public class DOCINFO { public string pDocName; public string pOutputFile; public string pDataType; }
            [DllImport("winspool.drv", CharSet = CharSet.Unicode, SetLastError = true)]
            public static extern bool OpenPrinter(string name, out IntPtr h, IntPtr d);
            [DllImport("winspool.drv", SetLastError = true)] public static extern bool ClosePrinter(IntPtr h);
            [DllImport("winspool.drv", CharSet = CharSet.Unicode, SetLastError = true)]
            public static extern bool StartDocPrinter(IntPtr h, int level, DOCINFO di);
            [DllImport("winspool.drv", SetLastError = true)] public static extern bool EndDocPrinter(IntPtr h);
            [DllImport("winspool.drv", SetLastError = true)] public static extern bool StartPagePrinter(IntPtr h);
            [DllImport("winspool.drv", SetLastError = true)] public static extern bool EndPagePrinter(IntPtr h);
            [DllImport("winspool.drv", SetLastError = true)]
            public static extern bool WritePrinter(IntPtr h, byte[] b, int n, out int escritos);
            public static string Enviar(string impresora, byte[] datos) {
                IntPtr h;
                if (!OpenPrinter(impresora, out h, IntPtr.Zero)) return "No existe la impresora";
                var di = new DOCINFO { pDocName = "Ticket POS", pDataType = "RAW" };
                int escritos = 0;
                bool ok = StartDocPrinter(h, 1, di) && StartPagePrinter(h) && WritePrinter(h, datos, datos.Length, out escritos);
                EndPagePrinter(h); EndDocPrinter(h); ClosePrinter(h);
                return ok && escritos == datos.Length ? "OK" : "Windows rechazó el trabajo";
            }
        }
        "@
        try {
            \$r = [ImpresoraRaw]::Enviar('{$nombre}', [System.IO.File]::ReadAllBytes('{$ruta}'))
            Write-Output \$r
        } catch {
            Write-Output ("ERROR: " + \$_.Exception.Message)
        }
        PS);

        exec('powershell -NoProfile -ExecutionPolicy Bypass -File "' . $script . '" 2>&1', $salida, $codigo);

        @unlink($archivo);
        @unlink($script);

        $resultado = trim(implode(' ', $salida));
        if ($resultado !== 'OK') {
            throw new ImpresionException("{$impresora->alias}: " . ($resultado ?: 'sin respuesta de Windows'));
        }
    }
}
