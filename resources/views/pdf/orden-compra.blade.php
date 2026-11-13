<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Orden de compra {{ $orden->numero }}</title>
<style>
    @page { margin: 28px 34px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; }
    h1 { font-size: 16px; margin: 0; }
    h2 { font-size: 12px; margin: 16px 0 6px; }
    .muted { color: #6b7280; }
    td { vertical-align: top; }
    table { width: 100%; border-collapse: collapse; }
    .tabla th { background: #f3f4f6; text-align: left; padding: 5px 6px; font-size: 9px; text-transform: uppercase; color: #4b5563; }
    .tabla td { padding: 6px; border-bottom: 1px solid #e5e7eb; }
    .num { text-align: right; white-space: nowrap; }
    .total td { font-size: 12px; font-weight: bold; border-bottom: none; }
    .firmas td { padding-top: 50px; text-align: center; width: 50%; }
</style>
</head>
<body>
@php($m = fn ($v) => '$' . number_format((float) $v, 2))
@php($c = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.'))

<table>
    <tr>
        <td>
            <h1>{{ $negocio->nombre }}</h1>
            @if($negocio->razon_social)<div>{{ $negocio->razon_social }}</div>@endif
            @if($negocio->direccion)<div class="muted">{{ $negocio->direccion }}</div>@endif
            <div class="muted">
                @if($negocio->telefono)Tel. {{ $negocio->telefono }}@endif
                @if($negocio->nit) · NIT {{ $negocio->nit }}@endif
                @if($negocio->nrc) · NRC {{ $negocio->nrc }}@endif
            </div>
        </td>
        <td style="text-align: right;">
            <h1>Orden de compra</h1>
            <div style="font-size: 13px;"><strong>{{ $orden->numero }}</strong></div>
            <div>Fecha: {{ $orden->fecha->format('d-m-Y') }}</div>
            @if($orden->fecha_esperada)<div>Entrega esperada: {{ $orden->fecha_esperada->format('d-m-Y') }}</div>@endif
        </td>
    </tr>
</table>

<h2>Proveedor</h2>
<div><strong>{{ $orden->proveedor->nombre }}</strong></div>
@if($orden->proveedor->nit)<div class="muted">NIT {{ $orden->proveedor->nit }}@if($orden->proveedor->nrc) · NRC {{ $orden->proveedor->nrc }}@endif</div>@endif
@if($orden->proveedor->contacto)<div>Atención: {{ $orden->proveedor->contacto }}</div>@endif
@if($orden->proveedor->telefono || $orden->proveedor->correo)<div class="muted">{{ collect([$orden->proveedor->telefono, $orden->proveedor->correo])->filter()->join(' · ') }}</div>@endif

<h2>Detalle</h2>
<table class="tabla">
    <thead><tr><th>Producto</th><th class="num">Cantidad</th><th>Unidad</th><th class="num">Costo unitario</th><th class="num">Subtotal</th></tr></thead>
    <tbody>
        @foreach($orden->detalles as $d)
            <tr>
                <td>{{ $d->producto->nombre }}</td>
                <td class="num">{{ $c($d->cantidad) }}</td>
                <td>{{ $d->unidad->nombre }}</td>
                <td class="num">{{ $m($d->costo_unitario) }}</td>
                <td class="num">{{ $m($d->subtotal) }}</td>
            </tr>
        @endforeach
        <tr class="total"><td colspan="4" class="num">Total estimado</td><td class="num">{{ $m($orden->total) }}</td></tr>
    </tbody>
</table>

@if($orden->observaciones)
<h2>Observaciones</h2>
<div>{{ $orden->observaciones }}</div>
@endif

<table class="firmas">
    <tr>
        <td>______________________________<br>Elaboró: {{ $orden->usuario?->nombre }}</td>
        <td>______________________________<br>Autorizó</td>
    </tr>
</table>
</body>
</html>
