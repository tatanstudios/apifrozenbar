<?php

namespace App\Exports;

use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Cualquier reporte de ReportesService a una hoja de Excel: título, negocio y
 * periodo arriba, encabezados, filas con su formato numérico y fila de totales.
 */
class ReporteExport implements FromArray, ShouldAutoSize, WithColumnFormatting, WithStyles, WithTitle
{
    const FILA_ENCABEZADO = 4;

    const FORMATOS = [
        'dinero'     => '"$"#,##0.00;[Red]-"$"#,##0.00',
        'costo'      => '"$"#,##0.0000',
        'numero'     => 'General',   // enteros sin decimales y fracciones solo cuando las hay
        'porcentaje' => '0.0"%"',
    ];

    public function __construct(private array $reporte, private string $negocio)
    {
    }

    public function title(): string
    {
        return mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', '', $this->reporte['titulo']), 0, 31);
    }

    private function valor(array $columna, $valor)
    {
        if ($valor === null) {
            return null;
        }
        if ($columna['formato'] === 'fecha') {
            return Carbon::parse($valor)->format('d-m-Y');
        }
        return in_array($columna['formato'], ['dinero', 'costo', 'numero', 'porcentaje'], true) ? (float) $valor : $valor;
    }

    public function array(): array
    {
        $columnas = $this->reporte['columnas'];

        $filas = [
            [$this->reporte['titulo']],
            ["{$this->negocio} · {$this->reporte['periodo']}"],
            [''], // una fila vacía de verdad: un arreglo vacío se omite
            array_column($columnas, 'titulo'),
        ];

        foreach ($this->reporte['filas'] as $fila) {
            $filas[] = array_map(fn ($c) => $this->valor($c, $fila[$c['clave']] ?? null), $columnas);
        }

        if ($this->reporte['totales']) {
            $filas[] = array_map(function ($c, $i) {
                if ($i === 0) {
                    return 'TOTAL';
                }
                return array_key_exists($c['clave'], $this->reporte['totales']) ? $this->valor($c, $this->reporte['totales'][$c['clave']]) : null;
            }, $columnas, array_keys($columnas));
        }

        return $filas;
    }

    public function columnFormats(): array
    {
        $formatos = [];
        foreach ($this->reporte['columnas'] as $i => $c) {
            if (isset(self::FORMATOS[$c['formato']])) {
                $formatos[Coordinate::stringFromColumnIndex($i + 1)] = self::FORMATOS[$c['formato']];
            }
        }
        return $formatos;
    }

    public function styles(Worksheet $hoja): array
    {
        $ultimaColumna = Coordinate::stringFromColumnIndex(count($this->reporte['columnas']));
        $estilos = [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            2 => ['font' => ['color' => ['rgb' => '6B7280']]],
            "A" . self::FILA_ENCABEZADO . ":{$ultimaColumna}" . self::FILA_ENCABEZADO => [
                'font' => ['bold' => true],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'F3F4F6']],
            ],
        ];

        if ($this->reporte['totales']) {
            $filaTotal = self::FILA_ENCABEZADO + count($this->reporte['filas']) + 1;
            $estilos["A{$filaTotal}:{$ultimaColumna}{$filaTotal}"] = [
                'font'    => ['bold' => true],
                'borders' => ['top' => ['borderStyle' => 'thin']],
            ];
        }

        $hoja->freezePane('A' . (self::FILA_ENCABEZADO + 1));

        return $estilos;
    }
}
