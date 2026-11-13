<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>{{ $reporte['titulo'] }}</title>
<style>
    @page { margin: 26px 28px 36px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: {{ count($reporte['columnas']) > 9 ? 8 : 9 }}px; color: #1f2937; }
    h1 { font-size: 15px; margin: 0; }
    .muted { color: #6b7280; }
    table { width: 100%; border-collapse: collapse; }
    .encabezado td { vertical-align: top; }
    .indicadores { margin: 12px 0; }
    .indicadores td { border: 1px solid #e5e7eb; padding: 6px 8px; }
    .indicadores .valor { font-size: 12px; font-weight: bold; }
    .tabla th { background: #f3f4f6; text-align: left; padding: 4px 5px; font-size: 8px; text-transform: uppercase; color: #4b5563; }
    .tabla td { padding: 4px 5px; border-bottom: 1px solid #e5e7eb; }
    .tabla tr.total td { font-weight: bold; border-top: 1.5px solid #9ca3af; border-bottom: none; }
    .num, .tabla th.num { text-align: right; white-space: nowrap; }
    .negativo { color: #b91c1c; }
    .pie { position: fixed; bottom: -22px; left: 0; right: 0; font-size: 8px; color: #9ca3af; text-align: center; }
</style>
</head>
<body>
@php
    $formatear = function ($valor, $formato) {
        if ($valor === null || $valor === '') return '';
        return match ($formato) {
            'dinero'     => ($valor < 0 ? '−' : '') . '$' . number_format(abs((float) $valor), 2),
            'costo'      => '$' . number_format((float) $valor, 4),
            'numero'     => rtrim(rtrim(number_format((float) $valor, 3), '0'), '.'),
            'porcentaje' => number_format((float) $valor, 1) . '%',
            'fecha'      => \Carbon\Carbon::parse($valor)->format('d-m-Y'),
            default      => $valor,
        };
    };
    $numerico = fn ($formato) => in_array($formato, ['dinero', 'costo', 'numero', 'porcentaje'], true);
@endphp

<div class="pie">{{ $negocio->nombre }} · {{ $reporte['titulo'] }} · generado el {{ $generado->format('d-m-Y H:i') }} por {{ $usuario }}</div>

<table class="encabezado">
    <tr>
        <td><h1>{{ $reporte['titulo'] }}</h1><div class="muted">{{ $reporte['periodo'] }}</div></td>
        <td style="text-align: right;">
            <strong>{{ $negocio->nombre }}</strong>
            @if($negocio->nit)<div class="muted">NIT {{ $negocio->nit }}@if($negocio->nrc) · NRC {{ $negocio->nrc }}@endif</div>@endif
        </td>
    </tr>
</table>

@if(count($reporte['indicadores']))
<table class="indicadores">
    <tr>
        @foreach($reporte['indicadores'] as $i)
            <td><div class="muted">{{ $i['titulo'] }}</div><div class="valor">{{ $formatear($i['valor'], $i['formato']) }}</div></td>
        @endforeach
    </tr>
</table>
@endif

<table class="tabla">
    <thead>
        <tr>
            @foreach($reporte['columnas'] as $c)
                <th class="{{ $numerico($c['formato']) ? 'num' : '' }}">{{ $c['titulo'] }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @forelse($reporte['filas'] as $fila)
            <tr>
                @foreach($reporte['columnas'] as $c)
                    @php($v = $fila[$c['clave']] ?? null)
                    <td class="{{ $numerico($c['formato']) ? 'num' : '' }} {{ is_numeric($v) && $v < 0 ? 'negativo' : '' }}">{{ $formatear($v, $c['formato']) }}</td>
                @endforeach
            </tr>
        @empty
            <tr><td colspan="{{ count($reporte['columnas']) }}" class="muted">Sin datos en el periodo</td></tr>
        @endforelse
        @if($reporte['totales'] && count($reporte['filas']))
            <tr class="total">
                @foreach($reporte['columnas'] as $n => $c)
                    <td class="{{ $numerico($c['formato']) ? 'num' : '' }}">
                        {{ $n === 0 ? 'TOTAL' : (array_key_exists($c['clave'], $reporte['totales']) ? $formatear($reporte['totales'][$c['clave']], $c['formato']) : '') }}
                    </td>
                @endforeach
            </tr>
        @endif
    </tbody>
</table>
</body>
</html>
