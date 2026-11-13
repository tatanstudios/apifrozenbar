<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Estado de cuenta · {{ $cliente->nombre }}</title>
<style>
    @page { margin: 28px 34px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; }
    h1 { font-size: 16px; margin: 0; }
    h2 { font-size: 12px; margin: 16px 0 6px; }
    .muted { color: #6b7280; }
    .encabezado td { vertical-align: top; }
    table { width: 100%; border-collapse: collapse; }
    .tabla th { background: #f3f4f6; text-align: left; padding: 5px 6px; font-size: 9px; text-transform: uppercase; color: #4b5563; }
    .tabla td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; }
    .num { text-align: right; white-space: nowrap; }
    .resumen td { padding: 6px 8px; border: 1px solid #e5e7eb; }
    .resumen .valor { font-size: 13px; font-weight: bold; }
    .vencida { color: #b91c1c; font-weight: bold; }
    .pie { margin-top: 20px; font-size: 8px; color: #9ca3af; text-align: center; }
</style>
</head>
<body>
@php($m = fn ($v) => ($v < 0 ? '−' : '') . '$' . number_format(abs($v), 2))

<table class="encabezado">
    <tr>
        <td>
            <h1>{{ $negocio->nombre }}</h1>
            @if($negocio->razon_social)<div>{{ $negocio->razon_social }}</div>@endif
            @if($negocio->direccion)<div class="muted">{{ $negocio->direccion }}</div>@endif
            <div class="muted">
                @if($negocio->telefono)Tel. {{ $negocio->telefono }}@endif
                @if($negocio->nit) · NIT {{ $negocio->nit }}@endif
            </div>
        </td>
        <td style="text-align: right;">
            <h1>Estado de cuenta</h1>
            <div>Del {{ \Carbon\Carbon::parse($estado['desde'])->format('d-m-Y') }} al {{ \Carbon\Carbon::parse($estado['hasta'])->format('d-m-Y') }}</div>
            <div class="muted">Emitido {{ $emitido->format('d-m-Y H:i') }}</div>
        </td>
    </tr>
</table>

<h2>Cliente</h2>
<table>
    <tr>
        <td><strong>{{ $cliente->nombre }}</strong>
            @if($cliente->documento)<br><span class="muted">DUI/NIT:</span> {{ $cliente->documento }}@endif
            @if($cliente->telefono)<br><span class="muted">Teléfono:</span> {{ $cliente->telefono }}@endif
            @if($cliente->direccion)<br><span class="muted">Dirección:</span> {{ $cliente->direccion }}@endif
        </td>
        <td style="text-align: right;">
            <span class="muted">Límite de crédito:</span> {{ $m((float) $cliente->limite_credito) }}<br>
            <span class="muted">Plazo:</span> {{ $cliente->dias_credito }} días
        </td>
    </tr>
</table>

<h2>Resumen del periodo</h2>
<table class="resumen">
    <tr>
        <td><div class="muted">Saldo anterior</div><div class="valor">{{ $m($estado['saldo_anterior']) }}</div></td>
        <td><div class="muted">Compras al crédito</div><div class="valor">{{ $m($estado['cargos']) }}</div></td>
        <td><div class="muted">Abonos y devoluciones</div><div class="valor">{{ $m($estado['abonos']) }}</div></td>
        <td><div class="muted">Saldo al {{ \Carbon\Carbon::parse($estado['hasta'])->format('d-m-Y') }}</div><div class="valor">{{ $m($estado['saldo_final']) }}</div></td>
        <td><div class="muted">Vencido hoy</div><div class="valor {{ $estado['vencido'] > 0 ? 'vencida' : '' }}">{{ $m($estado['vencido']) }}</div></td>
    </tr>
</table>

<h2>Movimientos</h2>
<table class="tabla">
    <thead>
        <tr><th>Fecha</th><th>Documento</th><th>Descripción</th><th class="num">Cargo</th><th class="num">Abono</th><th class="num">Saldo</th></tr>
    </thead>
    <tbody>
        <tr><td colspan="5" class="muted">Saldo anterior</td><td class="num">{{ $m($estado['saldo_anterior']) }}</td></tr>
        @forelse($estado['movimientos'] as $mov)
            <tr>
                <td>{{ \Carbon\Carbon::parse($mov['fecha'])->format('d-m-Y') }}</td>
                <td>{{ $mov['documento'] }}</td>
                <td>{{ $mov['descripcion'] }}</td>
                <td class="num">{{ $mov['cargo'] > 0 ? $m($mov['cargo']) : '' }}</td>
                <td class="num">{{ $mov['abono'] > 0 ? $m($mov['abono']) : '' }}</td>
                <td class="num">{{ $m($mov['saldo']) }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">Sin movimientos en el periodo</td></tr>
        @endforelse
    </tbody>
</table>

@if(count($estado['pendientes']))
<h2>Facturas pendientes</h2>
<table class="tabla">
    <thead>
        <tr><th>Venta</th><th>Fecha</th><th>Vence</th><th class="num">Monto</th><th class="num">Saldo</th><th>Estado</th></tr>
    </thead>
    <tbody>
        @foreach($estado['pendientes'] as $c)
            <tr>
                <td>{{ $c['venta'] }}</td>
                <td>{{ \Carbon\Carbon::parse($c['fecha'])->format('d-m-Y') }}</td>
                <td>{{ \Carbon\Carbon::parse($c['fecha_vencimiento'])->format('d-m-Y') }}</td>
                <td class="num">{{ $m($c['monto']) }}</td>
                <td class="num">{{ $m($c['saldo']) }}</td>
                <td class="{{ $c['vencida'] ? 'vencida' : '' }}">{{ $c['vencida'] ? "Vencida ({$c['dias_vencida']} días)" : 'Al día' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
@endif

<div class="pie">{{ $negocio->nombre }} · Este documento es un resumen de cuenta y no sustituye a los comprobantes de venta.</div>
</body>
</html>
