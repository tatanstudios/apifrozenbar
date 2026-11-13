<?php

namespace App\Http\Controllers\Api\Productos;

use App\Http\Controllers\Controller;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KardexApiController extends Controller
{
    // Filtro de fechas común: por defecto los últimos 30 días
    private function rangoFechas(Request $request): array
    {
        $hasta = $request->filled('hasta') ? Carbon::parse($request->hasta)->endOfDay() : now()->endOfDay();
        $desde = $request->filled('desde') ? Carbon::parse($request->desde)->startOfDay() : $hasta->copy()->subDays(29)->startOfDay();

        return [$desde, $hasta];
    }

    private function validarFiltros(Request $request, array $extra = [])
    {
        return validator($request->all(), array_merge([
            'desde'    => 'nullable|date',
            'hasta'    => 'nullable|date|after_or_equal:desde',
            'per_page' => 'nullable|integer|min:10|max:200',
        ], $extra), [
            'hasta.after_or_equal' => 'La fecha final debe ser igual o posterior a la inicial',
        ]);
    }

    private function formatoMovimiento(MovimientoInventario $m): array
    {
        $diferencia = $m->diferencia();

        return [
            'id'             => $m->id,
            'fecha'          => $m->created_at->format('Y-m-d H:i'),
            'tipo'           => $m->tipo,
            'tipo_texto'     => MovimientoInventario::TIPOS[$m->tipo] ?? $m->tipo,
            'referencia'     => $m->referenciaTexto(),
            'referencia_tipo'=> $m->referencia_tipo,
            'referencia_id'  => $m->referencia_id,
            'motivo'         => $m->motivo,
            'usuario'        => $m->usuario?->nombre,
            'turno'          => $m->turno?->etiqueta(),
            'entrada'        => $diferencia > 0 ? round($diferencia, 4) : 0,
            'salida'         => $diferencia < 0 ? round(abs($diferencia), 4) : 0,
            'saldo'          => (float) $m->stock_resultante,
            'costo_unitario' => (float) $m->costo_unitario,
            'costo_total'    => (float) $m->costo_total,
        ];
    }

    // GET /inventario/kardex/{producto}?desde=&hasta=&page=&per_page=
    // Movimientos en orden cronológico con saldo; incluye saldo inicial y totales del período
    public function kardex(Request $request, Producto $producto)
    {
        $validator = $this->validarFiltros($request);
        if ($validator->fails()) {
            return response()->json(['success' => 0, 'message' => $validator->errors()->first()], 422);
        }

        [$desde, $hasta] = $this->rangoFechas($request);

        $base = MovimientoInventario::where('producto_id', $producto->id);

        // Saldo antes del período: el resultado del último movimiento anterior
        $saldoInicial = (float) (clone $base)
            ->where('created_at', '<', $desde)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->value('stock_resultante');

        $periodo = (clone $base)->whereBetween('created_at', [$desde, $hasta]);

        $totales = (clone $periodo)->selectRaw('
                COALESCE(SUM(GREATEST(stock_resultante - stock_anterior, 0)), 0) as entradas,
                COALESCE(SUM(GREATEST(stock_anterior - stock_resultante, 0)), 0) as salidas,
                COALESCE(SUM(CASE WHEN stock_resultante > stock_anterior THEN costo_total ELSE 0 END), 0) as costo_entradas,
                COALESCE(SUM(CASE WHEN stock_resultante < stock_anterior THEN costo_total ELSE 0 END), 0) as costo_salidas
            ')->first();

        $movimientos = (clone $periodo)
            ->with(['usuario:id,nombre', 'turno.usuarioApertura:id,nombre', 'turno.usuarioCierre:id,nombre'])
            ->orderBy('created_at')->orderBy('id')
            ->paginate($request->integer('per_page', 50));

        $producto->load(['inventario', 'unidadMedida']);

        return response()->json([
            'success'  => 1,
            'producto' => [
                'id'             => $producto->id,
                'nombre'         => $producto->nombre,
                'sku'            => $producto->sku,
                'clase'          => $producto->clase,
                'unidad'         => $producto->unidadMedida?->abreviatura ?? $producto->unidadMedida?->nombre,
                'costo_unitario' => (float) $producto->costo_unitario,
                'stock_actual'   => (float) ($producto->inventario->stock_actual ?? 0),
                'stock_minimo'   => (float) ($producto->inventario->stock_minimo ?? 0),
            ],
            'desde'          => $desde->toDateString(),
            'hasta'          => $hasta->toDateString(),
            'saldo_inicial'  => $saldoInicial,
            'total_entradas' => (float) $totales->entradas,
            'total_salidas'  => (float) $totales->salidas,
            'costo_entradas' => round((float) $totales->costo_entradas, 2),
            'costo_salidas'  => round((float) $totales->costo_salidas, 2),
            'saldo_final'    => round($saldoInicial + (float) $totales->entradas - (float) $totales->salidas, 4),
            'movimientos'    => collect($movimientos->items())->map(fn ($m) => $this->formatoMovimiento($m)),
            'pagina'         => $movimientos->currentPage(),
            'total_paginas'  => $movimientos->lastPage(),
            'total'          => $movimientos->total(),
        ]);
    }

    // GET /inventario/movimientos?producto_id=&tipo=&usuario_id=&desde=&hasta=&page=
    // Historial general de movimientos, del más reciente al más antiguo
    public function movimientos(Request $request)
    {
        $validator = $this->validarFiltros($request, [
            'producto_id' => 'nullable|integer',
            'usuario_id'  => 'nullable|integer',
            'tipo'        => ['nullable', Rule::in(array_keys(MovimientoInventario::TIPOS))],
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => 0, 'message' => $validator->errors()->first()], 422);
        }

        [$desde, $hasta] = $this->rangoFechas($request);

        $movimientos = MovimientoInventario::with(['usuario:id,nombre', 'producto:id,nombre,sku', 'turno.usuarioApertura:id,nombre', 'turno.usuarioCierre:id,nombre'])
            ->whereBetween('created_at', [$desde, $hasta])
            ->when($request->filled('producto_id'), fn ($q) => $q->where('producto_id', $request->producto_id))
            ->when($request->filled('usuario_id'), fn ($q) => $q->where('usuario_id', $request->usuario_id))
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo', $request->tipo))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($request->integer('per_page', 25));

        return response()->json([
            'success'       => 1,
            'desde'         => $desde->toDateString(),
            'hasta'         => $hasta->toDateString(),
            'movimientos'   => collect($movimientos->items())->map(fn ($m) => $this->formatoMovimiento($m) + [
                'producto_id' => $m->producto_id,
                'producto'    => $m->producto?->nombre,
                'sku'         => $m->producto?->sku,
            ]),
            'pagina'        => $movimientos->currentPage(),
            'total_paginas' => $movimientos->lastPage(),
            'total'         => $movimientos->total(),
        ]);
    }
}
