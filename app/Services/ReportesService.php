<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Models\Merma;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\TurnoCaja;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reportes del negocio. Todos devuelven la misma forma tabular para que la
 * pantalla, Excel y PDF los muestren igual:
 *
 *   titulo, columnas [[clave, titulo, formato]], filas [[clave => valor]],
 *   totales [clave => valor] | null, indicadores [[titulo, valor, formato]], grafica?
 *
 * Formatos: texto, fecha, dinero, costo (4 decimales), numero, porcentaje.
 */
class ReportesService
{
    const REPORTES = [
        'ventas_diarias'        => ['titulo' => 'Ventas por día', 'grupo' => 'Ventas', 'fechas' => true],
        'ventas_hora'           => ['titulo' => 'Ventas por hora', 'grupo' => 'Ventas', 'fechas' => true],
        'ventas_empleado'       => ['titulo' => 'Ventas por empleado', 'grupo' => 'Ventas', 'fechas' => true],
        'productos_agregados'   => ['titulo' => 'Quién agregó cada producto', 'grupo' => 'Ventas', 'fechas' => true],
        'ventas_metodo'         => ['titulo' => 'Ventas por forma de pago', 'grupo' => 'Ventas', 'fechas' => true],
        'productos_vendidos'    => ['titulo' => 'Productos más vendidos', 'grupo' => 'Ventas', 'fechas' => true],
        'resultados'            => ['titulo' => 'Utilidad estimada', 'grupo' => 'Ventas', 'fechas' => true],
        'cierres_caja'          => ['titulo' => 'Cierres de caja', 'grupo' => 'Caja', 'fechas' => true],
        'compras'               => ['titulo' => 'Libro de compras', 'grupo' => 'Compras y gastos', 'fechas' => true],
        'gastos'                => ['titulo' => 'Gastos', 'grupo' => 'Compras y gastos', 'fechas' => true],
        'inventario_valorizado' => ['titulo' => 'Inventario valorizado', 'grupo' => 'Inventario', 'fechas' => false],
        'kardex'                => ['titulo' => 'Kárdex de un producto', 'grupo' => 'Inventario', 'fechas' => true],
        'produccion'            => ['titulo' => 'Producción', 'grupo' => 'Producción', 'fechas' => true],
        'mermas'                => ['titulo' => 'Mermas', 'grupo' => 'Producción', 'fechas' => true],
        'cartera_clientes'      => ['titulo' => 'Cuentas por cobrar', 'grupo' => 'Cartera', 'fechas' => false],
        'cartera_proveedores'   => ['titulo' => 'Cuentas por pagar', 'grupo' => 'Cartera', 'fechas' => false],
    ];

    public static function generar(string $tipo, array $filtros): array
    {
        if (!isset(self::REPORTES[$tipo])) {
            throw new NegocioException('Reporte no válido');
        }

        $hasta = Carbon::parse($filtros['hasta'] ?? today())->endOfDay();
        $desde = Carbon::parse($filtros['desde'] ?? $hasta->copy()->startOfMonth())->startOfDay();
        if ($desde->gt($hasta)) {
            throw new NegocioException('La fecha inicial no puede ser mayor que la final');
        }
        if ($desde->diffInDays($hasta) > 366) {
            throw new NegocioException('El periodo no puede ser mayor a un año');
        }

        $metodo = lcfirst(str_replace('_', '', ucwords($tipo, '_')));
        $reporte = self::$metodo($desde, $hasta, $filtros);

        return [
            'tipo'        => $tipo,
            'titulo'      => self::REPORTES[$tipo]['titulo'],
            'periodo'     => self::REPORTES[$tipo]['fechas']
                ? ($desde->isSameDay($hasta) ? $desde->format('d-m-Y') : "Del {$desde->format('d-m-Y')} al {$hasta->format('d-m-Y')}")
                : 'Al ' . now()->format('d-m-Y H:i'),
            'desde'       => $desde->toDateString(),
            'hasta'       => $hasta->toDateString(),
            'totales'     => null,
            'indicadores' => [],
            'grafica'     => null,
            ...$reporte,
        ];
    }

    // Turno del registro (quién lo abrió y quién lo cerró) para las consultas de los reportes
    private const COLUMNAS_TURNO = ['tc.id as turno_id', 'tc.abierto_at as turno_abierto', 'tc.cerrado_at as turno_cerrado',
        'tca.nombre as turno_abrio', 'tcc.nombre as turno_cerro'];

    private static function unirTurno($consulta, string $alias)
    {
        return $consulta->leftJoin('turnos_caja as tc', 'tc.id', '=', "{$alias}.turno_id")
            ->leftJoin('administradores as tca', 'tca.id', '=', 'tc.usuario_apertura_id')
            ->leftJoin('administradores as tcc', 'tcc.id', '=', 'tc.usuario_cierre_id');
    }

    private static function turno($r): ?string
    {
        return TurnoCaja::texto($r->turno_id, $r->turno_abrio, $r->turno_abierto, $r->turno_cerro, $r->turno_cerrado);
    }

    private static function col(string $clave, string $titulo, string $formato = 'texto'): array
    {
        return ['clave' => $clave, 'titulo' => $titulo, 'formato' => $formato];
    }

    private static function sumar($filas, array $claves): array
    {
        return collect($claves)->mapWithKeys(fn ($c) => [$c => round(collect($filas)->sum($c), 4)])->all();
    }

    private static function margen(float $utilidad, float $base): ?float
    {
        return $base > 0 ? round($utilidad / $base * 100, 1) : null;
    }

    // ── Ventas ────────────────────────────────────────────────

    /**
     * Solo las ventas con algo cobrable: una venta que es puro consumo interno
     * (la cena del personal a $0.00) no es un ticket ni cuenta como venta.
     */
    private static function conAlgoVendido($query, string $alias = 'ventas')
    {
        return $query->whereExists(fn ($e) => $e->from('ventas_detalle as vdc')
            ->whereColumn('vdc.venta_id', "{$alias}.id")->where('vdc.consumo_interno', false));
    }

    /**
     * Ventas vigentes y devoluciones por día. Las devoluciones restan su parte
     * sin IVA (en la proporción de su venta) y su costo si el producto volvió
     * al inventario; lo dañado sigue contando como costo. El consumo interno no
     * es costo de ventas: se informa aparte, al costo.
     */
    private static function ventasPorDia(Carbon $desde, Carbon $hasta): array
    {
        $ventas = DB::table('ventas')
            ->where('estado', '!=', 'anulada')->whereBetween('fecha_venta', [$desde, $hasta])
            ->groupBy(DB::raw('DATE(fecha_venta)'))
            ->selectRaw('DATE(fecha_venta) as dia, COUNT(*) as tickets, SUM(total) as total, SUM(descuento_total) as descuentos,
                SUM(base_imponible) as base, SUM(impuesto) as iva, SUM(costo_total) as costo')
            ->get()->keyBy('dia');

        $soloConsumo = DB::table('ventas')
            ->where('estado', '!=', 'anulada')->whereBetween('fecha_venta', [$desde, $hasta])
            ->whereNotExists(fn ($e) => $e->from('ventas_detalle as vdc')
                ->whereColumn('vdc.venta_id', 'ventas.id')->where('vdc.consumo_interno', false))
            ->groupBy(DB::raw('DATE(fecha_venta)'))
            ->selectRaw('DATE(fecha_venta) as dia, COUNT(*) as tickets')
            ->pluck('tickets', 'dia');

        $consumo = DB::table('ventas_detalle as vd')
            ->join('ventas as v', 'v.id', '=', 'vd.venta_id')
            ->where('vd.consumo_interno', true)
            ->where('v.estado', '!=', 'anulada')->whereBetween('v.fecha_venta', [$desde, $hasta])
            ->groupBy(DB::raw('DATE(v.fecha_venta)'))
            ->selectRaw('DATE(v.fecha_venta) as dia, SUM(vd.cantidad * vd.costo_unitario) as costo')
            ->pluck('costo', 'dia');

        $devoluciones = DB::table('devoluciones as d')
            ->join('ventas as v', 'v.id', '=', 'd.venta_id')
            ->whereBetween('d.created_at', [$desde, $hasta])
            ->groupBy(DB::raw('DATE(d.created_at)'))
            ->selectRaw('DATE(d.created_at) as dia, SUM(d.total) as total,
                SUM(d.total * CASE WHEN v.total > 0 THEN v.base_imponible / v.total ELSE 1 END) as base')
            ->get()->keyBy('dia');

        $costoDevuelto = DB::table('devoluciones_detalle as dd')
            ->join('devoluciones as d', 'd.id', '=', 'dd.devolucion_id')
            ->join('ventas_detalle as vd', 'vd.id', '=', 'dd.venta_detalle_id')
            ->where('dd.reingresa_inventario', true)
            ->whereBetween('d.created_at', [$desde, $hasta])
            ->groupBy(DB::raw('DATE(d.created_at)'))
            ->selectRaw('DATE(d.created_at) as dia, SUM(dd.cantidad * vd.costo_unitario) as costo')
            ->pluck('costo', 'dia');

        $dias = collect($ventas->keys())->merge($devoluciones->keys())->unique()->sort()->values();

        return $dias->map(function ($dia) use ($ventas, $devoluciones, $costoDevuelto, $soloConsumo, $consumo) {
            $v = $ventas->get($dia);
            $d = $devoluciones->get($dia);
            $base = (float) ($v->base ?? 0) - (float) ($d->base ?? 0);
            $costoConsumo = (float) ($consumo[$dia] ?? 0);
            $costo = (float) ($v->costo ?? 0) - $costoConsumo - (float) ($costoDevuelto[$dia] ?? 0);

            return [
                'fecha'        => $dia,
                'tickets'      => (int) ($v->tickets ?? 0) - (int) ($soloConsumo[$dia] ?? 0),
                'consumo_interno' => round($costoConsumo, 2),
                'ventas'       => round((float) ($v->total ?? 0), 2),
                'descuentos'   => round((float) ($v->descuentos ?? 0), 2),
                'devoluciones' => round((float) ($d->total ?? 0), 2),
                'neto'         => round((float) ($v->total ?? 0) - (float) ($d->total ?? 0), 2),
                'base'         => round($base, 2),
                'costo'        => round($costo, 2),
                'utilidad'     => round($base - $costo, 2),
                'margen'       => self::margen($base - $costo, $base),
            ];
        })->all();
    }

    private static function ventasDiarias(Carbon $desde, Carbon $hasta): array
    {
        $filas = self::ventasPorDia($desde, $hasta);
        $totales = self::sumar($filas, ['tickets', 'ventas', 'descuentos', 'devoluciones', 'neto', 'base', 'costo', 'utilidad', 'consumo_interno']);
        $totales['margen'] = self::margen($totales['utilidad'], $totales['base']);

        return [
            'columnas' => [
                self::col('fecha', 'Fecha', 'fecha'), self::col('tickets', 'Tickets', 'numero'),
                self::col('ventas', 'Ventas', 'dinero'), self::col('descuentos', 'Descuentos', 'dinero'),
                self::col('devoluciones', 'Devoluciones', 'dinero'), self::col('neto', 'Venta neta', 'dinero'),
                self::col('costo', 'Costo', 'dinero'), self::col('utilidad', 'Utilidad', 'dinero'),
                self::col('margen', 'Margen', 'porcentaje'), self::col('consumo_interno', 'Consumo interno (costo)', 'dinero'),
            ],
            'filas'       => $filas,
            'totales'     => $totales,
            'indicadores' => [
                ['titulo' => 'Venta neta', 'valor' => $totales['neto'], 'formato' => 'dinero'],
                ['titulo' => 'Tickets', 'valor' => $totales['tickets'], 'formato' => 'numero'],
                ['titulo' => 'Ticket promedio', 'valor' => $totales['tickets'] > 0 ? round($totales['ventas'] / $totales['tickets'], 2) : 0, 'formato' => 'dinero'],
                ['titulo' => 'Utilidad estimada', 'valor' => $totales['utilidad'], 'formato' => 'dinero'],
            ],
            'grafica' => ['tipo' => 'barra', 'etiqueta' => 'fecha', 'series' => ['neto', 'utilidad']],
        ];
    }

    private static function ventasHora(Carbon $desde, Carbon $hasta): array
    {
        $filas = self::conAlgoVendido(DB::table('ventas'))
            ->where('estado', '!=', 'anulada')->whereBetween('fecha_venta', [$desde, $hasta])
            ->groupBy(DB::raw('HOUR(fecha_venta)'))
            ->selectRaw('HOUR(fecha_venta) as hora, COUNT(*) as tickets, SUM(total) as total')
            ->orderBy('hora')->get()
            ->map(fn ($r) => [
                'hora'     => sprintf('%02d:00 – %02d:59', $r->hora, $r->hora),
                'tickets'  => (int) $r->tickets,
                'total'    => round((float) $r->total, 2),
                'promedio' => $r->tickets > 0 ? round((float) $r->total / $r->tickets, 2) : 0,
            ])->all();

        $totales = self::sumar($filas, ['tickets', 'total']);
        $pico = collect($filas)->sortByDesc('total')->first();

        return [
            'columnas' => [
                self::col('hora', 'Hora'), self::col('tickets', 'Tickets', 'numero'),
                self::col('total', 'Ventas', 'dinero'), self::col('promedio', 'Ticket promedio', 'dinero'),
            ],
            'filas'       => $filas,
            'totales'     => [...$totales, 'promedio' => $totales['tickets'] > 0 ? round($totales['total'] / $totales['tickets'], 2) : 0],
            'indicadores' => $pico ? [['titulo' => 'Hora de más venta', 'valor' => $pico['hora'], 'formato' => 'texto']] : [],
            'grafica'     => ['tipo' => 'barra', 'etiqueta' => 'hora', 'series' => ['total']],
        ];
    }

    private static function ventasEmpleado(Carbon $desde, Carbon $hasta): array
    {
        $filas = self::conAlgoVendido(DB::table('ventas as v'), 'v')
            ->join('administradores as a', 'a.id', '=', 'v.usuario_id')
            ->whereBetween('v.fecha_venta', [$desde, $hasta])
            ->groupBy('v.usuario_id', 'a.nombre')
            ->selectRaw("a.nombre as empleado,
                SUM(CASE WHEN v.estado != 'anulada' THEN 1 ELSE 0 END) as tickets,
                SUM(CASE WHEN v.estado != 'anulada' THEN v.total ELSE 0 END) as total,
                SUM(CASE WHEN v.estado != 'anulada' THEN v.descuento_total ELSE 0 END) as descuentos,
                SUM(CASE WHEN v.estado = 'anulada' THEN 1 ELSE 0 END) as anuladas")
            ->orderByDesc('total')->get()
            ->map(fn ($r) => [
                'empleado'   => $r->empleado,
                'tickets'    => (int) $r->tickets,
                'total'      => round((float) $r->total, 2),
                'promedio'   => $r->tickets > 0 ? round((float) $r->total / $r->tickets, 2) : 0,
                'descuentos' => round((float) $r->descuentos, 2),
                'anuladas'   => (int) $r->anuladas,
            ])->all();

        return [
            'columnas' => [
                self::col('empleado', 'Empleado'), self::col('tickets', 'Tickets', 'numero'),
                self::col('total', 'Ventas', 'dinero'), self::col('promedio', 'Ticket promedio', 'dinero'),
                self::col('descuentos', 'Descuentos', 'dinero'), self::col('anuladas', 'Anuladas', 'numero'),
            ],
            'filas'   => $filas,
            'totales' => self::sumar($filas, ['tickets', 'total', 'descuentos', 'anuladas']),
            'grafica' => ['tipo' => 'barra', 'etiqueta' => 'empleado', 'series' => ['total']],
        ];
    }

    /**
     * Quién agregó cada producto: en mesas y pedidos de mostrador cada línea guarda
     * quién la agregó (aunque otro cobre); en venta rápida es quien hizo la venta.
     * Filtro opcional usuario_id. Lo cancelado se lista con quién lo canceló y no suma.
     */
    private static function productosAgregados(Carbon $desde, Carbon $hasta, array $filtros = []): array
    {
        $empleado = !empty($filtros['usuario_id']) ? (int) $filtros['usuario_id'] : null;
        $estados = ['pendiente' => 'Sin enviar', 'enviado' => 'Enviado', 'en_preparacion' => 'Enviado', 'listo' => 'Enviado',
            'entregado' => 'Entregado', 'cancelado' => 'Cancelado'];

        // Mesas y pedidos de mostrador
        $deOrdenes = DB::table('ordenes_items as i')
            ->join('ordenes as o', 'o.id', '=', 'i.orden_id')
            ->join('terminales as t', 't.id', '=', 'o.terminal_id')
            ->join('ordenes_tickets as ot', 'ot.id', '=', 'i.ticket_id')
            ->join('administradores as a', 'a.id', '=', 'i.usuario_id')
            ->leftJoin('administradores as ac', 'ac.id', '=', 'i.cancelado_por')
            ->leftJoin('ventas as v', 'v.id', '=', 'i.venta_id')
            ->whereBetween('i.created_at', [$desde, $hasta])
            ->when($empleado, fn ($q) => $q->where('i.usuario_id', $empleado))
            ->get(['i.created_at', 'i.usuario_id', 'a.nombre as empleado', 't.nombre as terminal', 't.tipo as terminal_tipo',
                'o.numero as orden', 'ot.nombre as cuenta', 'i.nombre_producto', 'i.modificadores', 'i.ajustes', 'i.cantidad',
                'i.precio_unitario', 'i.estado', 'i.motivo_cancelacion', 'ac.nombre as cancelo', 'v.numero as venta', 'v.estado as venta_estado'])
            ->map(function ($r) use ($estados) {
                $extras = collect(\App\Models\Modificador::textos(json_decode($r->modificadores ?? '[]', true) ?: []))->join(', ');
                $cancelado = $r->estado === 'cancelado';
                $anulada = $r->venta_estado === 'anulada';
                return [
                    'momento'  => $r->created_at,
                    'usuario_id' => (int) $r->usuario_id,
                    'fecha'    => Carbon::parse($r->created_at)->format('d-m-Y H:i'),
                    'empleado' => $r->empleado,
                    'lugar'    => $r->terminal_tipo === 'caja' ? 'Pedido de mostrador' : $r->terminal,
                    'cuenta'   => "{$r->orden} · {$r->cuenta}",
                    'producto' => $r->nombre_producto . ($extras ? " ({$extras})" : ''),
                    'cantidad' => (float) $r->cantidad,
                    'importe'  => $cancelado || $anulada ? 0.0 : round((float) $r->cantidad * (float) $r->precio_unitario, 2),
                    'estado'   => $cancelado ? 'Cancelado' : ($r->venta ? ($anulada ? "Venta {$r->venta} anulada" : "Cobrado en {$r->venta}") : $estados[$r->estado] ?? $r->estado),
                    'detalle'  => $cancelado ? trim(($r->cancelo ? "Canceló {$r->cancelo}" : 'Cancelado') . ($r->motivo_cancelacion ? ": {$r->motivo_cancelacion}" : '')) : '',
                ];
            });

        // Venta rápida: la venta no viene de una mesa ni de un pedido de mostrador
        $deVentaRapida = DB::table('ventas_detalle as d')
            ->join('ventas as v', 'v.id', '=', 'd.venta_id')
            ->join('administradores as a', 'a.id', '=', 'v.usuario_id')
            ->whereNull('v.orden_id')
            ->whereBetween('v.fecha_venta', [$desde, $hasta])
            ->when($empleado, fn ($q) => $q->where('v.usuario_id', $empleado))
            ->get(['v.fecha_venta', 'v.usuario_id', 'a.nombre as empleado', 'v.numero', 'v.estado', 'd.nombre_producto', 'd.cantidad', 'd.subtotal', 'd.consumo_interno'])
            ->map(fn ($r) => [
                'momento'  => $r->fecha_venta,
                'usuario_id' => (int) $r->usuario_id,
                'fecha'    => Carbon::parse($r->fecha_venta)->format('d-m-Y H:i'),
                'empleado' => $r->empleado,
                'lugar'    => 'Venta rápida',
                'cuenta'   => $r->numero,
                'producto' => $r->nombre_producto,
                'cantidad' => (float) $r->cantidad,
                'importe'  => $r->estado === 'anulada' ? 0.0 : round((float) $r->subtotal, 2),
                'estado'   => $r->estado === 'anulada' ? 'Venta anulada' : ($r->consumo_interno ? 'Consumo interno' : 'Cobrado'),
                'detalle'  => '',
            ]);

        $todas = $deOrdenes->concat($deVentaRapida)->sortBy('momento')->values();

        // Quién agregó más (sin lo cancelado ni lo anulado)
        $porEmpleado = $todas->groupBy('empleado')
            ->map(fn ($g, $nombre) => ['titulo' => $nombre, 'valor' => round($g->sum('importe'), 2), 'formato' => 'dinero'])
            ->sortByDesc('valor')->values();

        // Para el filtro «Empleado»: quienes agregaron algo en el periodo (sin filtrar)
        $empleados = $empleado
            ? DB::table('administradores')->where('id', $empleado)->get(['id', 'nombre'])
            : $todas->unique('usuario_id')->map(fn ($f) => ['id' => $f['usuario_id'], 'nombre' => $f['empleado']])->sortBy('nombre')->values();

        $filas = $todas->map(fn ($f) => collect($f)->except(['momento', 'usuario_id'])->all())->all();

        return [
            'columnas' => [
                self::col('fecha', 'Fecha y hora'), self::col('empleado', 'Agregó'), self::col('lugar', 'Dónde'),
                self::col('cuenta', 'Cuenta / venta'), self::col('producto', 'Producto'), self::col('cantidad', 'Cantidad', 'numero'),
                self::col('importe', 'Importe', 'dinero'), self::col('estado', 'Estado'), self::col('detalle', 'Cancelación'),
            ],
            'filas'       => $filas,
            'totales'     => ['cantidad' => round($todas->where('importe', '>', 0)->sum('cantidad'), 4), 'importe' => round($todas->sum('importe'), 2)],
            'indicadores' => $porEmpleado->take(4)->all(),
            'empleados'   => $empleados,
        ];
    }

    private static function ventasMetodo(Carbon $desde, Carbon $hasta): array
    {
        $cobrado = DB::table('ventas_pagos as p')
            ->join('ventas as v', 'v.id', '=', 'p.venta_id')
            ->join('metodos_pago as m', 'm.id', '=', 'p.metodo_pago_id')
            ->where('v.estado', '!=', 'anulada')->whereBetween('v.fecha_venta', [$desde, $hasta])
            ->groupBy('m.id', 'm.nombre')
            ->selectRaw('m.id, m.nombre, COUNT(DISTINCT v.id) as ventas, SUM(p.monto) as total')
            ->get()->keyBy('id');

        $devuelto = DB::table('devoluciones')->whereBetween('created_at', [$desde, $hasta])
            ->groupBy('metodo_pago_id')->selectRaw('metodo_pago_id, SUM(total) as total')->pluck('total', 'metodo_pago_id');

        $nombres = DB::table('metodos_pago')->pluck('nombre', 'id');
        $ids = $cobrado->keys()->merge($devuelto->keys())->unique();
        $total = (float) $cobrado->sum('total') - (float) $devuelto->sum();

        $filas = $ids->map(function ($id) use ($cobrado, $devuelto, $nombres, $total) {
            $neto = (float) ($cobrado[$id]->total ?? 0) - (float) ($devuelto[$id] ?? 0);
            return [
                'metodo'       => $nombres[$id] ?? "#{$id}",
                'ventas'       => (int) ($cobrado[$id]->ventas ?? 0),
                'cobrado'      => round((float) ($cobrado[$id]->total ?? 0), 2),
                'devoluciones' => round((float) ($devuelto[$id] ?? 0), 2),
                'neto'         => round($neto, 2),
                'porcentaje'   => $total > 0 ? round($neto / $total * 100, 1) : 0,
            ];
        })->sortByDesc('neto')->values()->all();

        return [
            'columnas' => [
                self::col('metodo', 'Forma de pago'), self::col('ventas', 'Ventas', 'numero'),
                self::col('cobrado', 'Cobrado', 'dinero'), self::col('devoluciones', 'Devoluciones', 'dinero'),
                self::col('neto', 'Neto', 'dinero'), self::col('porcentaje', '% del total', 'porcentaje'),
            ],
            'filas'   => $filas,
            'totales' => [...self::sumar($filas, ['ventas', 'cobrado', 'devoluciones', 'neto']), 'porcentaje' => 100],
            'grafica' => ['tipo' => 'dona', 'etiqueta' => 'metodo', 'series' => ['neto']],
        ];
    }

    private static function productosVendidos(Carbon $desde, Carbon $hasta, array $filtros): array
    {
        // Lo cobrado por línea ya con el descuento general repartido y sin lo devuelto
        $neto = '(vd.subtotal * CASE WHEN v.subtotal > 0 THEN (v.subtotal - v.descuento_global) / v.subtotal ELSE 1 END
                  * (vd.cantidad - vd.cantidad_devuelta) / vd.cantidad)';

        $filas = DB::table('ventas_detalle as vd')
            ->join('ventas as v', 'v.id', '=', 'vd.venta_id')
            ->where('v.estado', '!=', 'anulada')->whereBetween('v.fecha_venta', [$desde, $hasta])
            ->where('vd.consumo_interno', false)
            ->when(!empty($filtros['categoria_id']), fn ($q) => $q->whereExists(fn ($e) => $e->from('categoria_producto as cp')
                ->whereColumn('cp.producto_id', 'vd.producto_id')->where('cp.categoria_id', $filtros['categoria_id'])))
            ->groupBy('vd.producto_id', 'vd.nombre_producto', 'vd.unidad')
            ->selectRaw("vd.nombre_producto as producto, vd.unidad,
                SUM(vd.cantidad - vd.cantidad_devuelta) as cantidad,
                SUM($neto) as ventas,
                SUM($neto * CASE WHEN v.total > 0 THEN v.base_imponible / v.total ELSE 1 END) as base,
                SUM((vd.cantidad - vd.cantidad_devuelta) * vd.costo_unitario) as costo")
            ->havingRaw('SUM(vd.cantidad - vd.cantidad_devuelta) > 0')
            ->orderByDesc('ventas')->limit(min((int) ($filtros['limite'] ?? 100), 500))->get()
            ->map(fn ($r) => [
                'producto' => $r->producto,
                'unidad'   => $r->unidad,
                'cantidad' => round((float) $r->cantidad, 3),
                'ventas'   => round((float) $r->ventas, 2),
                'base'     => round((float) $r->base, 2),
                'costo'    => round((float) $r->costo, 2),
                'utilidad' => round((float) $r->base - (float) $r->costo, 2),
                'margen'   => self::margen((float) $r->base - (float) $r->costo, (float) $r->base),
            ])->all();

        $totales = self::sumar($filas, ['ventas', 'base', 'costo', 'utilidad']);
        $totales['margen'] = self::margen($totales['utilidad'], $totales['base']);

        return [
            'columnas' => [
                self::col('producto', 'Producto'), self::col('cantidad', 'Cantidad', 'numero'), self::col('unidad', 'Unidad'),
                self::col('ventas', 'Ventas', 'dinero'), self::col('base', 'Sin IVA', 'dinero'), self::col('costo', 'Costo', 'dinero'),
                self::col('utilidad', 'Utilidad', 'dinero'), self::col('margen', 'Margen', 'porcentaje'),
            ],
            'filas'   => $filas,
            'totales' => $totales,
            'grafica' => ['tipo' => 'barra_horizontal', 'etiqueta' => 'producto', 'series' => ['ventas'], 'limite' => 10],
        ];
    }

    /**
     * Estado de resultados sencillo por día: utilidad bruta de ventas, menos
     * mermas y gastos. Las compras no restan (son inventario: su costo se
     * reconoce al vender, producir o mermar).
     */
    private static function resultados(Carbon $desde, Carbon $hasta): array
    {
        $ventas = collect(self::ventasPorDia($desde, $hasta))->keyBy('fecha');
        $mermas = DB::table('mermas')->where('anulada', false)->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->groupBy('fecha')->selectRaw('fecha, SUM(costo_total) as total')->pluck('total', 'fecha');
        $gastos = DB::table('gastos')->where('anulado', false)->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->groupBy('fecha')->selectRaw('fecha, SUM(monto) as total')->pluck('total', 'fecha');

        $dias = $ventas->keys()->merge($mermas->keys())->merge($gastos->keys())->unique()->sort()->values();

        $filas = $dias->map(function ($dia) use ($ventas, $mermas, $gastos) {
            $v = $ventas->get($dia);
            $bruta = (float) ($v['utilidad'] ?? 0);
            $merma = (float) ($mermas[$dia] ?? 0);
            $gasto = (float) ($gastos[$dia] ?? 0);
            $consumo = (float) ($v['consumo_interno'] ?? 0);
            return [
                'fecha'           => $dia,
                'ventas'          => (float) ($v['base'] ?? 0),
                'costo'           => (float) ($v['costo'] ?? 0),
                'utilidad_bruta'  => round($bruta, 2),
                'mermas'          => round($merma, 2),
                'consumo_interno' => round($consumo, 2),
                'gastos'          => round($gasto, 2),
                'resultado'       => round($bruta - $merma - $consumo - $gasto, 2),
            ];
        })->all();

        $totales = self::sumar($filas, ['ventas', 'costo', 'utilidad_bruta', 'mermas', 'consumo_interno', 'gastos', 'resultado']);

        return [
            'columnas' => [
                self::col('fecha', 'Fecha', 'fecha'), self::col('ventas', 'Ventas sin IVA', 'dinero'),
                self::col('costo', 'Costo de ventas', 'dinero'), self::col('utilidad_bruta', 'Utilidad bruta', 'dinero'),
                self::col('mermas', 'Mermas', 'dinero'), self::col('consumo_interno', 'Consumo interno', 'dinero'),
                self::col('gastos', 'Gastos', 'dinero'), self::col('resultado', 'Resultado', 'dinero'),
            ],
            'filas'       => $filas,
            'totales'     => $totales,
            'indicadores' => [
                ['titulo' => 'Ventas sin IVA', 'valor' => $totales['ventas'], 'formato' => 'dinero'],
                ['titulo' => 'Utilidad bruta', 'valor' => $totales['utilidad_bruta'], 'formato' => 'dinero'],
                ['titulo' => 'Mermas, consumo y gastos', 'valor' => round($totales['mermas'] + $totales['consumo_interno'] + $totales['gastos'], 2), 'formato' => 'dinero'],
                ['titulo' => 'Resultado', 'valor' => $totales['resultado'], 'formato' => 'dinero'],
            ],
            'grafica' => ['tipo' => 'linea', 'etiqueta' => 'fecha', 'series' => ['utilidad_bruta', 'resultado']],
        ];
    }

    // ── Caja ──────────────────────────────────────────────────

    private static function cierresCaja(Carbon $desde, Carbon $hasta): array
    {
        $filas = DB::table('turnos_caja as t')
            ->join('cajas as c', 'c.id', '=', 't.caja_id')
            ->join('administradores as a', 'a.id', '=', 't.usuario_apertura_id')
            ->leftJoin('administradores as b', 'b.id', '=', 't.usuario_cierre_id')
            ->where('t.estado', 'cerrado')->whereBetween('t.cerrado_at', [$desde, $hasta])
            ->orderBy('t.cerrado_at')
            ->get(['t.id', 'c.nombre as caja', 'a.nombre as abrio', 'b.nombre as cerro', 't.abierto_at', 't.cerrado_at',
                't.fondo_inicial', 't.total_ventas', 't.total_ingresos', 't.total_egresos', 't.efectivo_esperado', 't.efectivo_contado', 't.diferencia'])
            ->map(fn ($t) => [
                'turno'     => "#{$t->id}",
                'caja'      => $t->caja,
                'abrio'     => $t->abrio,
                'cerro'     => $t->cerro,
                'apertura'  => Carbon::parse($t->abierto_at)->format('d-m-Y H:i'),
                'cierre'    => Carbon::parse($t->cerrado_at)->format('d-m-Y H:i'),
                'fondo'     => (float) $t->fondo_inicial,
                'ventas'    => (float) $t->total_ventas,
                'ingresos'  => (float) $t->total_ingresos,
                'egresos'   => (float) $t->total_egresos,
                'esperado'  => (float) $t->efectivo_esperado,
                'contado'   => (float) $t->efectivo_contado,
                'diferencia' => (float) $t->diferencia,
            ])->all();

        $totales = self::sumar($filas, ['ventas', 'ingresos', 'egresos', 'diferencia']);
        $faltantes = collect($filas)->where('diferencia', '<', 0)->count();

        return [
            'columnas' => [
                self::col('turno', 'Turno'), self::col('caja', 'Caja'), self::col('abrio', 'Abrió'), self::col('cerro', 'Cerró'),
                self::col('apertura', 'Apertura'), self::col('cierre', 'Cierre'), self::col('fondo', 'Fondo', 'dinero'),
                self::col('ventas', 'Ventas', 'dinero'), self::col('ingresos', 'Ingresos', 'dinero'), self::col('egresos', 'Egresos', 'dinero'),
                self::col('esperado', 'Esperado', 'dinero'), self::col('contado', 'Contado', 'dinero'), self::col('diferencia', 'Diferencia', 'dinero'),
            ],
            'filas'       => $filas,
            'totales'     => $totales,
            'indicadores' => [
                ['titulo' => 'Turnos cerrados', 'valor' => count($filas), 'formato' => 'numero'],
                ['titulo' => 'Turnos con faltante', 'valor' => $faltantes, 'formato' => 'numero'],
                ['titulo' => 'Diferencia acumulada', 'valor' => $totales['diferencia'], 'formato' => 'dinero'],
            ],
        ];
    }

    // ── Compras y gastos ──────────────────────────────────────

    private static function compras(Carbon $desde, Carbon $hasta, array $filtros): array
    {
        $tipos = ['factura' => 'Factura', 'ccf' => 'Crédito fiscal', 'ticket' => 'Ticket', 'otro' => 'Otro'];

        $filas = self::unirTurno(DB::table('compras as c'), 'c')
            ->join('proveedores as p', 'p.id', '=', 'c.proveedor_id')
            ->join('administradores as u', 'u.id', '=', 'c.usuario_id')
            ->leftJoin('cuentas_pagar as cp', 'cp.compra_id', '=', 'c.id')
            ->where('c.estado', 'registrada')
            ->whereBetween('c.fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->when(!empty($filtros['proveedor_id']), fn ($q) => $q->where('c.proveedor_id', $filtros['proveedor_id']))
            ->orderBy('c.fecha')->orderBy('c.id')
            ->get(['c.fecha', 'c.numero', 'p.nombre as proveedor', 'p.nit', 'p.nrc', 'c.tipo_documento', 'c.numero_documento',
                'c.condicion', 'c.subtotal', 'c.impuesto', 'c.total', 'cp.saldo', 'u.nombre as usuario', ...self::COLUMNAS_TURNO])
            ->map(fn ($c) => [
                'fecha'     => $c->fecha,
                'numero'    => $c->numero,
                'proveedor' => $c->proveedor,
                'nit'       => trim(($c->nit ?? '') . ($c->nrc ? " / {$c->nrc}" : '')),
                'documento' => trim($tipos[$c->tipo_documento] . ' ' . ($c->numero_documento ?? '')),
                'condicion' => $c->condicion === 'contado' ? 'Contado' : 'Crédito',
                'subtotal'  => (float) $c->subtotal,
                'iva'       => (float) $c->impuesto,
                'total'     => (float) $c->total,
                'saldo'     => (float) ($c->saldo ?? 0),
                'usuario'   => $c->usuario,
                'turno'     => self::turno($c),
            ])->all();

        $totales = self::sumar($filas, ['subtotal', 'iva', 'total', 'saldo']);

        return [
            'columnas' => [
                self::col('fecha', 'Fecha', 'fecha'), self::col('numero', 'Compra'), self::col('proveedor', 'Proveedor'),
                self::col('nit', 'NIT / NRC'), self::col('documento', 'Documento'), self::col('condicion', 'Condición'),
                self::col('subtotal', 'Subtotal', 'dinero'), self::col('iva', 'IVA', 'dinero'),
                self::col('total', 'Total', 'dinero'), self::col('saldo', 'Saldo', 'dinero'),
                self::col('usuario', 'Registró'), self::col('turno', 'Turno'),
            ],
            'filas'       => $filas,
            'totales'     => $totales,
            'indicadores' => [
                ['titulo' => 'Compras', 'valor' => $totales['total'], 'formato' => 'dinero'],
                ['titulo' => 'IVA (crédito fiscal)', 'valor' => $totales['iva'], 'formato' => 'dinero'],
                ['titulo' => 'Pendiente de pago', 'valor' => $totales['saldo'], 'formato' => 'dinero'],
            ],
        ];
    }

    private static function gastos(Carbon $desde, Carbon $hasta, array $filtros): array
    {
        $filas = self::unirTurno(DB::table('gastos as g'), 'g')
            ->join('categorias_gasto as c', 'c.id', '=', 'g.categoria_id')
            ->join('administradores as u', 'u.id', '=', 'g.usuario_id')
            ->join('metodos_pago as m', 'm.id', '=', 'g.metodo_pago_id')
            ->leftJoin('proveedores as p', 'p.id', '=', 'g.proveedor_id')
            ->where('g.anulado', false)
            ->whereBetween('g.fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->when(!empty($filtros['categoria_id']), fn ($q) => $q->where('g.categoria_id', $filtros['categoria_id']))
            ->orderBy('g.fecha')->orderBy('g.id')
            ->get(['g.fecha', 'g.numero', 'c.nombre as categoria', 'g.descripcion', 'p.nombre as proveedor', 'g.numero_documento',
                'm.nombre as metodo', 'g.origen', 'g.monto', 'u.nombre as usuario', ...self::COLUMNAS_TURNO])
            ->map(fn ($g) => [
                'fecha'       => $g->fecha,
                'numero'      => $g->numero,
                'categoria'   => $g->categoria,
                'descripcion' => $g->descripcion,
                'proveedor'   => $g->proveedor,
                'documento'   => $g->numero_documento,
                'pago'        => $g->metodo . ($g->origen === 'caja' ? ' (caja)' : ''),
                'monto'       => (float) $g->monto,
                'usuario'     => $g->usuario,
                'turno'       => self::turno($g),
            ])->all();

        $porCategoria = collect($filas)->groupBy('categoria')->map(fn ($g, $c) => ['categoria' => $c, 'monto' => round($g->sum('monto'), 2)])
            ->sortByDesc('monto')->values();

        return [
            'columnas' => [
                self::col('fecha', 'Fecha', 'fecha'), self::col('numero', 'Gasto'), self::col('categoria', 'Categoría'),
                self::col('descripcion', 'Descripción'), self::col('proveedor', 'Proveedor'), self::col('documento', 'Documento'),
                self::col('pago', 'Pago'), self::col('monto', 'Monto', 'dinero'),
                self::col('usuario', 'Registró'), self::col('turno', 'Turno'),
            ],
            'filas'       => $filas,
            'totales'     => self::sumar($filas, ['monto']),
            'indicadores' => $porCategoria->take(4)->map(fn ($c) => ['titulo' => $c['categoria'], 'valor' => $c['monto'], 'formato' => 'dinero'])->all(),
            'grafica'     => ['tipo' => 'dona', 'etiqueta' => 'categoria', 'series' => ['monto'], 'datos' => $porCategoria->all()],
        ];
    }

    // ── Inventario ────────────────────────────────────────────

    private static function inventarioValorizado(Carbon $desde, Carbon $hasta, array $filtros): array
    {
        $clases = ['producto_terminado' => 'Producto terminado', 'materia_prima' => 'Materia prima', 'reventa' => 'Reventa', 'empaque' => 'Empaque'];

        $filas = Producto::with(['unidadMedida:id,abreviatura', 'inventario:producto_id,stock_actual,stock_minimo'])
            ->where('activo', true)->where('controla_stock', true)
            ->when(!empty($filtros['clase']), fn ($q) => $q->where('clase', $filtros['clase']))
            ->orderBy('clase')->orderBy('nombre')->get()
            ->map(function (Producto $p) use ($clases) {
                $stock = (float) ($p->inventario?->stock_actual ?? 0);
                return [
                    'producto'    => $p->nombre,
                    'clase'       => $clases[$p->clase] ?? $p->clase,
                    'unidad'      => $p->unidadMedida?->abreviatura,
                    'stock'       => round($stock, 3),
                    'minimo'      => round((float) ($p->inventario?->stock_minimo ?? 0), 3),
                    'costo'       => (float) $p->costo_unitario,
                    'valor'       => round(max($stock, 0) * (float) $p->costo_unitario, 2),
                    'precio'      => (float) $p->precio_venta,
                    'valor_venta' => $p->se_vende ? round(max($stock, 0) * (float) $p->precio_venta, 2) : 0,
                ];
            })->all();

        $porClase = collect($filas)->groupBy('clase')->map(fn ($g, $c) => ['titulo' => $c, 'valor' => round($g->sum('valor'), 2), 'formato' => 'dinero'])->values();

        return [
            'columnas' => [
                self::col('producto', 'Producto'), self::col('clase', 'Clase'), self::col('unidad', 'Unidad'),
                self::col('stock', 'Existencia', 'numero'), self::col('minimo', 'Mínimo', 'numero'),
                self::col('costo', 'Costo promedio', 'costo'), self::col('valor', 'Valor al costo', 'dinero'),
                self::col('precio', 'Precio', 'dinero'), self::col('valor_venta', 'Valor a precio de venta', 'dinero'),
            ],
            'filas'       => $filas,
            'totales'     => self::sumar($filas, ['valor', 'valor_venta']),
            'indicadores' => [['titulo' => 'Valor total', 'valor' => round(collect($filas)->sum('valor'), 2), 'formato' => 'dinero'], ...$porClase->all()],
        ];
    }

    private static function kardex(Carbon $desde, Carbon $hasta, array $filtros): array
    {
        $producto = Producto::with('unidadMedida:id,abreviatura')->find($filtros['producto_id'] ?? 0);
        if (!$producto) {
            throw new NegocioException('Elige el producto del kárdex');
        }

        $anterior = MovimientoInventario::where('producto_id', $producto->id)->where('created_at', '<', $desde)
            ->orderByDesc('id')->value('stock_resultante');

        $filas = MovimientoInventario::with(['usuario:id,nombre', 'turno.usuarioApertura:id,nombre', 'turno.usuarioCierre:id,nombre'])
            ->where('producto_id', $producto->id)->whereBetween('created_at', [$desde, $hasta])
            ->orderBy('id')->get()
            ->map(function (MovimientoInventario $m) {
                $entra = (float) $m->stock_resultante >= (float) $m->stock_anterior;
                $diferencia = abs((float) $m->stock_resultante - (float) $m->stock_anterior);
                return [
                    'fecha'    => $m->created_at->format('d-m-Y H:i'),
                    'tipo'     => MovimientoInventario::TIPOS[$m->tipo] ?? $m->tipo,
                    'motivo'   => $m->motivo,
                    'entrada'  => $entra ? round($diferencia, 4) : null,
                    'salida'   => !$entra ? round($diferencia, 4) : null,
                    'saldo'    => round((float) $m->stock_resultante, 4),
                    'costo'    => (float) $m->costo_unitario,
                    'importe'  => round((float) $m->costo_total, 2),
                    'usuario'  => $m->usuario?->nombre,
                    'turno'    => $m->turno?->etiqueta(),
                ];
            })->all();

        return [
            'titulo'      => "Kárdex: {$producto->nombre}" . ($producto->unidadMedida ? " ({$producto->unidadMedida->abreviatura})" : ''),
            'columnas'    => [
                self::col('fecha', 'Fecha'), self::col('tipo', 'Movimiento'), self::col('motivo', 'Detalle'),
                self::col('entrada', 'Entrada', 'numero'), self::col('salida', 'Salida', 'numero'), self::col('saldo', 'Saldo', 'numero'),
                self::col('costo', 'Costo unitario', 'costo'), self::col('importe', 'Importe', 'dinero'), self::col('usuario', 'Usuario'), self::col('turno', 'Turno'),
            ],
            'filas'       => $filas,
            'totales'     => self::sumar($filas, ['entrada', 'salida']),
            'indicadores' => [
                ['titulo' => 'Saldo inicial', 'valor' => round((float) ($anterior ?? 0), 3), 'formato' => 'numero'],
                ['titulo' => 'Saldo final', 'valor' => round((float) (collect($filas)->last()['saldo'] ?? $anterior ?? 0), 3), 'formato' => 'numero'],
                ['titulo' => 'Costo promedio actual', 'valor' => (float) $producto->costo_unitario, 'formato' => 'costo'],
            ],
        ];
    }

    // ── Producción ────────────────────────────────────────────

    private static function produccion(Carbon $desde, Carbon $hasta): array
    {
        $filas = self::unirTurno(DB::table('producciones as p'), 'p')
            ->join('productos as pr', 'pr.id', '=', 'p.producto_id')
            ->join('administradores as a', 'a.id', '=', 'p.usuario_id')
            ->where('p.estado', 'registrada')->whereBetween('p.fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->orderBy('p.fecha')->orderBy('p.id')
            ->get(['p.fecha', 'p.numero', 'pr.nombre as producto', 'p.cantidad_planificada', 'p.cantidad_producida', 'p.costo_total', 'p.costo_unitario', 'a.nombre as usuario', ...self::COLUMNAS_TURNO])
            ->map(fn ($p) => [
                'fecha'       => $p->fecha,
                'numero'      => $p->numero,
                'producto'    => $p->producto,
                'planificada' => (float) $p->cantidad_planificada,
                'producida'   => (float) $p->cantidad_producida,
                'rendimiento' => (float) $p->cantidad_planificada > 0 ? round((float) $p->cantidad_producida / (float) $p->cantidad_planificada * 100, 1) : null,
                'costo'       => round((float) $p->costo_total, 2),
                'unitario'    => (float) $p->costo_unitario,
                'usuario'     => $p->usuario,
                'turno'       => self::turno($p),
            ])->all();

        return [
            'columnas' => [
                self::col('fecha', 'Fecha', 'fecha'), self::col('numero', 'Producción'), self::col('producto', 'Producto'),
                self::col('planificada', 'Planificado', 'numero'), self::col('producida', 'Producido', 'numero'),
                self::col('rendimiento', 'Rendimiento', 'porcentaje'), self::col('costo', 'Costo', 'dinero'),
                self::col('unitario', 'Costo unitario', 'costo'), self::col('usuario', 'Registró'), self::col('turno', 'Turno'),
            ],
            'filas'   => $filas,
            'totales' => self::sumar($filas, ['costo']),
        ];
    }

    private static function mermas(Carbon $desde, Carbon $hasta, array $filtros): array
    {
        $filas = self::unirTurno(DB::table('mermas as m'), 'm')
            ->join('productos as p', 'p.id', '=', 'm.producto_id')
            ->join('administradores as a', 'a.id', '=', 'm.usuario_id')
            ->where('m.anulada', false)->whereBetween('m.fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->when(!empty($filtros['motivo']), fn ($q) => $q->where('m.motivo', $filtros['motivo']))
            ->orderBy('m.fecha')->orderBy('m.id')
            ->get(['m.fecha', 'p.nombre as producto', 'm.cantidad', 'm.motivo', 'm.detalle', 'm.costo_unitario', 'm.costo_total', 'a.nombre as usuario', ...self::COLUMNAS_TURNO])
            ->map(fn ($m) => [
                'fecha'    => $m->fecha,
                'producto' => $m->producto,
                'cantidad' => (float) $m->cantidad,
                'motivo'   => Merma::MOTIVOS[$m->motivo] ?? $m->motivo,
                'detalle'  => $m->detalle,
                'unitario' => (float) $m->costo_unitario,
                'costo'    => round((float) $m->costo_total, 2),
                'usuario'  => $m->usuario,
                'turno'    => self::turno($m),
            ])->all();

        return [
            'columnas' => [
                self::col('fecha', 'Fecha', 'fecha'), self::col('producto', 'Producto'), self::col('cantidad', 'Cantidad', 'numero'),
                self::col('motivo', 'Motivo'), self::col('detalle', 'Detalle'), self::col('unitario', 'Costo unitario', 'costo'),
                self::col('costo', 'Costo', 'dinero'), self::col('usuario', 'Registró'), self::col('turno', 'Turno'),
            ],
            'filas'   => $filas,
            'totales' => self::sumar($filas, ['costo']),
        ];
    }

    // ── Cartera ───────────────────────────────────────────────

    private static function carteraClientes(): array
    {
        $hoy = today()->toDateString();

        $filas = DB::table('clientes as c')
            ->join('cuentas_cobrar as cc', 'cc.cliente_id', '=', 'c.id')
            ->where('cc.estado', 'pendiente')
            ->groupBy('c.id', 'c.nombre', 'c.telefono', 'c.limite_credito')
            ->selectRaw("c.nombre as cliente, c.telefono, c.limite_credito as limite, SUM(cc.saldo) as saldo,
                SUM(CASE WHEN cc.fecha_vencimiento < ? THEN cc.saldo ELSE 0 END) as vencido,
                MIN(cc.fecha_vencimiento) as proximo", [$hoy])
            ->orderByDesc('vencido')->orderByDesc('saldo')->get()
            ->map(fn ($r) => [
                'cliente'   => $r->cliente,
                'telefono'  => $r->telefono,
                'limite'    => (float) $r->limite,
                'saldo'     => round((float) $r->saldo, 2),
                'vencido'   => round((float) $r->vencido, 2),
                'por_vencer' => round((float) $r->saldo - (float) $r->vencido, 2),
                'proximo'   => $r->proximo,
            ])->all();

        return [
            'columnas' => [
                self::col('cliente', 'Cliente'), self::col('telefono', 'Teléfono'), self::col('limite', 'Límite', 'dinero'),
                self::col('saldo', 'Saldo', 'dinero'), self::col('vencido', 'Vencido', 'dinero'),
                self::col('por_vencer', 'Por vencer', 'dinero'), self::col('proximo', 'Vencimiento más antiguo', 'fecha'),
            ],
            'filas'   => $filas,
            'totales' => self::sumar($filas, ['saldo', 'vencido', 'por_vencer']),
        ];
    }

    private static function carteraProveedores(): array
    {
        $hoy = today()->toDateString();

        $filas = DB::table('proveedores as p')
            ->join('cuentas_pagar as cp', 'cp.proveedor_id', '=', 'p.id')
            ->where('cp.estado', 'pendiente')
            ->groupBy('p.id', 'p.nombre', 'p.contacto', 'p.telefono')
            ->selectRaw("p.nombre as proveedor, p.contacto, p.telefono, SUM(cp.saldo) as saldo,
                SUM(CASE WHEN cp.fecha_vencimiento < ? THEN cp.saldo ELSE 0 END) as vencido,
                MIN(cp.fecha_vencimiento) as proximo", [$hoy])
            ->orderBy('proximo')->get()
            ->map(fn ($r) => [
                'proveedor' => $r->proveedor,
                'contacto'  => trim(($r->contacto ?? '') . ' ' . ($r->telefono ?? '')),
                'saldo'     => round((float) $r->saldo, 2),
                'vencido'   => round((float) $r->vencido, 2),
                'por_vencer' => round((float) $r->saldo - (float) $r->vencido, 2),
                'proximo'   => $r->proximo,
            ])->all();

        return [
            'columnas' => [
                self::col('proveedor', 'Proveedor'), self::col('contacto', 'Contacto'), self::col('saldo', 'Saldo', 'dinero'),
                self::col('vencido', 'Vencido', 'dinero'), self::col('por_vencer', 'Por vencer', 'dinero'),
                self::col('proximo', 'Próximo vencimiento', 'fecha'),
            ],
            'filas'   => $filas,
            'totales' => self::sumar($filas, ['saldo', 'vencido', 'por_vencer']),
        ];
    }

    // ── Panel de inicio ───────────────────────────────────────

    /**
     * Indicadores del día para el panel. Lo financiero solo se incluye si el
     * usuario puede ver reportes.
     */
    public static function panel(bool $financiero, ?string $desde = null, ?string $hasta = null): array
    {
        $hoy = today();
        $inicio = Carbon::parse($desde ?? $hoy->copy()->startOfMonth())->startOfDay();
        $fin = Carbon::parse($hasta ?? $hoy)->endOfDay();
        $ayer = $hoy->copy()->subDay();

        $dia = fn (Carbon $d) => self::conAlgoVendido(DB::table('ventas'))->where('estado', '!=', 'anulada')
            ->whereBetween('fecha_venta', [$d->copy()->startOfDay(), $d->copy()->endOfDay()])
            ->selectRaw('COUNT(*) as tickets, COALESCE(SUM(total), 0) as total')->first();
        $vHoy = $dia($hoy);
        $vAyer = $dia($ayer);

        $panel = [
            'fecha'  => $hoy->toDateString(),
            'ventas' => [
                'hoy'            => round((float) $vHoy->total, 2),
                'tickets'        => (int) $vHoy->tickets,
                'ticket_promedio' => $vHoy->tickets > 0 ? round((float) $vHoy->total / $vHoy->tickets, 2) : 0,
                'ayer'           => round((float) $vAyer->total, 2),
                'variacion'      => (float) $vAyer->total > 0 ? round(((float) $vHoy->total - (float) $vAyer->total) / (float) $vAyer->total * 100, 1) : null,
            ],
            'por_hora' => DB::table('ventas')->where('estado', '!=', 'anulada')
                ->whereBetween('fecha_venta', [$hoy->copy()->startOfDay(), $hoy->copy()->endOfDay()])
                ->groupBy(DB::raw('HOUR(fecha_venta)'))->selectRaw('HOUR(fecha_venta) as hora, SUM(total) as total')
                ->orderBy('hora')->get()->map(fn ($r) => ['hora' => (int) $r->hora, 'total' => round((float) $r->total, 2)]),
            'top_productos' => DB::table('ventas_detalle as vd')->join('ventas as v', 'v.id', '=', 'vd.venta_id')
                ->where('v.estado', '!=', 'anulada')->whereBetween('v.fecha_venta', [$hoy->copy()->startOfDay(), $hoy->copy()->endOfDay()])
                ->where('vd.consumo_interno', false)
                ->groupBy('vd.nombre_producto', 'vd.unidad')
                ->selectRaw('vd.nombre_producto as producto, vd.unidad, SUM(vd.cantidad - vd.cantidad_devuelta) as cantidad, SUM(vd.subtotal) as total')
                ->orderByDesc('total')->limit(5)->get()
                ->map(fn ($r) => ['producto' => $r->producto, 'unidad' => $r->unidad, 'cantidad' => round((float) $r->cantidad, 3), 'total' => round((float) $r->total, 2)]),
            'alertas' => [
                'stock_bajo'        => DB::table('inventario as i')->join('productos as p', 'p.id', '=', 'i.producto_id')
                    ->where('p.activo', true)->where('p.controla_stock', true)->where('i.stock_minimo', '>', 0)
                    ->whereColumn('i.stock_actual', '<=', 'i.stock_minimo')->count(),
                'pedidos_hoy'       => DB::table('pedidos')->where('tipo', 'especial')->where('estado', 'abierto')
                    ->whereBetween('fecha_entrega', [$hoy->copy()->startOfDay(), $hoy->copy()->endOfDay()])->count(),
                'pedidos_atrasados' => DB::table('pedidos')->where('tipo', 'especial')->where('estado', 'abierto')
                    ->where('fecha_entrega', '<', $hoy->copy()->startOfDay())->count(),
                'turnos_abiertos'   => DB::table('turnos_caja')->where('estado', 'abierto')->count(),
            ],
            'rango' => self::resumenRango($inicio, $fin, $financiero),
        ];

        if ($financiero) {
            $inicio30 = $hoy->copy()->subDays(29);
            $panel['ultimos_30'] = collect(self::ventasPorDia($inicio30->copy()->startOfDay(), $hoy->copy()->endOfDay()))
                ->map(fn ($d) => ['fecha' => $d['fecha'], 'neto' => $d['neto'], 'utilidad' => $d['utilidad']])->values();
            $panel['mes'] = [
                'ventas'  => round(collect(self::ventasPorDia($hoy->copy()->startOfMonth(), $hoy->copy()->endOfDay()))->sum('neto'), 2),
                'gastos'  => round((float) DB::table('gastos')->where('anulado', false)->whereBetween('fecha', [$hoy->copy()->startOfMonth()->toDateString(), $hoy->toDateString()])->sum('monto'), 2),
                'mermas'  => round((float) DB::table('mermas')->where('anulada', false)->whereBetween('fecha', [$hoy->copy()->startOfMonth()->toDateString(), $hoy->toDateString()])->sum('costo_total'), 2),
                'compras' => round((float) DB::table('compras')->where('estado', 'registrada')->whereBetween('fecha', [$hoy->copy()->startOfMonth()->toDateString(), $hoy->toDateString()])->sum('total'), 2),
            ];
            $panel['cartera'] = [
                'por_cobrar'         => round((float) DB::table('cuentas_cobrar')->where('estado', 'pendiente')->sum('saldo'), 2),
                'por_cobrar_vencido' => round((float) DB::table('cuentas_cobrar')->where('estado', 'pendiente')->where('fecha_vencimiento', '<', $hoy->toDateString())->sum('saldo'), 2),
                'por_pagar'          => round((float) DB::table('cuentas_pagar')->where('estado', 'pendiente')->sum('saldo'), 2),
                'por_pagar_semana'   => round((float) DB::table('cuentas_pagar')->where('estado', 'pendiente')->where('fecha_vencimiento', '<=', $hoy->copy()->addDays(7)->toDateString())->sum('saldo'), 2),
            ];
        }

        return $panel;
    }

    /** Resumen del periodo elegido en el panel: totales, últimos pedidos y productos más vendidos. */
    private static function resumenRango(Carbon $inicio, Carbon $fin, bool $financiero): array
    {
        $dias = collect(self::ventasPorDia($inicio, $fin));

        $ventas = DB::table('ventas as v')->leftJoin('clientes as c', 'c.id', '=', 'v.cliente_id')
            ->whereBetween('v.fecha_venta', [$inicio, $fin]);

        $ultimos = (clone $ventas)->orderByDesc('v.fecha_venta')->orderByDesc('v.id')->limit(10)
            ->get(['v.id', 'v.numero', 'c.nombre as cliente', 'v.fecha_venta', 'v.estado', 'v.total']);
        $pagos = DB::table('ventas_pagos as vp')->join('metodos_pago as m', 'm.id', '=', 'vp.metodo_pago_id')
            ->whereIn('vp.venta_id', $ultimos->pluck('id'))->get(['vp.venta_id', 'm.nombre'])
            ->groupBy('venta_id')->map(fn ($p) => $p->pluck('nombre')->unique()->implode(', '));

        $productos = DB::table('ventas_detalle as vd')->join('ventas as v', 'v.id', '=', 'vd.venta_id')
            ->leftJoin('productos as p', 'p.id', '=', 'vd.producto_id')
            ->where('v.estado', '!=', 'anulada')->whereBetween('v.fecha_venta', [$inicio, $fin])
            ->where('vd.consumo_interno', false);

        $resumen = [
            'desde'          => $inicio->toDateString(),
            'hasta'          => $fin->toDateString(),
            'total_ventas'   => round($dias->sum('neto'), 2),
            'tickets'        => $dias->sum('tickets'),
            'total_pedidos'  => (clone $ventas)->count(),
            'ultimos_pedidos' => $ultimos->map(fn ($v) => [
                'id'      => $v->id,
                'numero'  => $v->numero,
                'cliente' => $v->cliente ?? 'Consumidor final',
                'fecha'   => $v->fecha_venta,
                'pago'    => $pagos[$v->id] ?? '',
                'estado'  => $v->estado,
                'total'   => round((float) $v->total, 2),
            ]),
            'total_productos' => (clone $productos)->distinct()->count('vd.producto_id'),
            // Se agrupa por producto (no por descripción) para que un frappé con extras cuente como frappé
            'mas_vendidos' => (clone $productos)->groupBy('vd.producto_id', 'p.nombre', 'p.imagen')
                ->selectRaw('p.nombre as producto, p.imagen, MAX(vd.unidad) as unidad,
                    SUM(vd.cantidad - vd.cantidad_devuelta) as cantidad, SUM(vd.subtotal) as total')
                ->orderByDesc('cantidad')->limit(10)->get()
                ->map(fn ($r) => ['producto' => $r->producto, 'imagen' => $r->imagen, 'unidad' => $r->unidad,
                    'cantidad' => round((float) $r->cantidad, 3), 'total' => round((float) $r->total, 2)]),
        ];

        if ($financiero) {
            $resumen['total_gastos'] = round((float) DB::table('gastos')->where('anulado', false)
                ->whereBetween('fecha', [$inicio->toDateString(), $fin->toDateString()])->sum('monto'), 2);
            // Utilidad bruta: venta sin IVA menos el costo de lo vendido
            $resumen['utilidad_bruta'] = round($dias->sum('utilidad'), 2);
            $resumen['utilidad_neta'] = round($resumen['utilidad_bruta'] - $resumen['total_gastos'], 2);
        }

        return $resumen;
    }
}
