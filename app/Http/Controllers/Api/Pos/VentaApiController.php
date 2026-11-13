<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Models\Devolucion;
use App\Models\Venta;
use App\Services\DevolucionService;
use App\Services\VentaService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class VentaApiController extends Controller
{
    private function errorValidacion($validator)
    {
        return response()->json([
            'success' => 0,
            'message' => $validator->errors()->first(),
            'errors'  => $validator->errors(),
        ], 422);
    }

    // Venta completa para el ticket y el detalle
    public static function formato(Venta $venta): array
    {
        $venta->loadMissing([
            'detalles.producto', 'pagos.metodoPago', 'caja', 'usuario:id,nombre', 'anuladaPor:id,nombre', 'anulacionAutorizadaPor:id,nombre',
            'descuentoAutorizadoPor:id,nombre', 'devoluciones.usuario:id,nombre', 'devoluciones.metodoPago',
            'cliente:id,nombre,documento,telefono', 'cuentaCobrar', 'pedido:id,numero,tipo',
        ]);

        return [
            'id'               => $venta->id,
            'uuid'             => $venta->uuid,
            'numero'           => $venta->numero,
            'numero_provisional' => $venta->numero_provisional,
            'origen'           => $venta->origen,
            'conflictos'       => $venta->conflictos ?? [],
            'estado'           => $venta->estado,
            'fecha'            => $venta->fecha_venta->format('Y-m-d H:i'),
            'caja'             => $venta->caja?->nombre,
            'turno_id'         => $venta->turno_id,
            'usuario'          => $venta->usuario?->nombre,
            'subtotal'         => (float) $venta->subtotal,
            'descuento_lineas' => (float) $venta->descuento_lineas,
            'descuento_global' => (float) $venta->descuento_global,
            'descuento_total'  => (float) $venta->descuento_total,
            'base_imponible'   => (float) $venta->base_imponible,
            'impuesto'         => (float) $venta->impuesto,
            'total'            => (float) $venta->total,
            'cambio'           => (float) $venta->cambio,
            'anticipo_aplicado' => (float) $venta->anticipo_aplicado,
            'cliente'          => $venta->cliente ? [
                'id'        => $venta->cliente->id,
                'nombre'    => $venta->cliente->nombre,
                'documento' => $venta->cliente->documento,
                'telefono'  => $venta->cliente->telefono,
                'direccion' => $venta->cliente->direccion,
            ] : null,
            'credito'          => $venta->cuentaCobrar ? [
                'monto'             => (float) $venta->cuentaCobrar->monto,
                'saldo'             => (float) $venta->cuentaCobrar->saldo,
                'fecha_vencimiento' => $venta->cuentaCobrar->fecha_vencimiento->toDateString(),
                'estado'            => $venta->cuentaCobrar->estado,
            ] : null,
            'pedido'           => $venta->pedido ? ['numero' => $venta->pedido->numero, 'tipo' => $venta->pedido->tipo] : null,
            'observaciones'    => $venta->observaciones,
            'descuento_autorizado_por' => $venta->descuentoAutorizadoPor?->nombre,
            'anulada_por'      => $venta->anuladaPor?->nombre,
            'anulacion_autorizada_por' => $venta->anulacionAutorizadaPor?->nombre,
            'anulada_at'       => $venta->anulada_at?->format('Y-m-d H:i'),
            'motivo_anulacion' => $venta->motivo_anulacion,
            'detalles'         => $venta->detalles->map(fn ($d) => [
                'id'                => $d->id,
                'producto_id'       => $d->producto_id,
                'nombre'            => $d->nombre_producto,
                // Con extras, el ticket muestra el producto solo y cada extra debajo («* Leche»)
                'nombre_base'       => $d->partesTicket()[0],
                'extras'            => $d->partesTicket()[1],
                'unidad'            => $d->unidad,
                'cantidad'          => (float) $d->cantidad,
                'precio_unitario'   => (float) $d->precio_unitario,
                'descuento'         => (float) $d->descuento,
                'subtotal'          => (float) $d->subtotal,
                'cantidad_devuelta' => (float) $d->cantidad_devuelta,
                'disponible'        => $d->cantidadDisponibleParaDevolver(),
            ]),
            'pagos'            => $venta->pagos->map(fn ($p) => [
                'metodo'     => $p->metodoPago?->nombre,
                'tipo'       => $p->metodoPago?->tipo,
                'monto'      => (float) $p->monto,
                'recibido'   => $p->monto_recibido !== null ? (float) $p->monto_recibido : null,
                'cambio'     => (float) $p->cambio,
                'referencia' => $p->referencia,
            ]),
            'devoluciones'     => $venta->devoluciones->map(fn (Devolucion $d) => [
                'id'      => $d->id,
                'numero'  => $d->numero,
                'fecha'   => $d->created_at->format('Y-m-d H:i'),
                'total'   => (float) $d->total,
                'motivo'  => $d->motivo,
                'metodo'  => $d->metodoPago?->nombre,
                'usuario' => $d->usuario?->nombre,
            ]),
        ];
    }

    // POST /pos/ventas
    public function store(Request $request)
    {
        $validator = validator($request->all(), [
            'uuid'                    => 'required|uuid',
            'caja_id'                 => 'required|integer|exists:cajas,id',
            'items'                   => 'required|array|min:1|max:200',
            'items.*.producto_id'     => 'required|integer',
            'items.*.cantidad'        => 'required|numeric|gt:0|max:99999',
            'items.*.descuento_tipo'  => 'nullable|in:porcentaje,monto',
            'items.*.descuento_valor' => 'nullable|numeric|min:0',
            // Extras elegidos y lo que el cliente pidió distinto de la receta ("sin huevo")
            'items.*.modificadores'   => 'nullable|array|max:60',
            'items.*.modificadores.*' => 'integer',
            'items.*.ajustes'         => 'nullable|array|max:50',
            'items.*.ajustes.*.producto_id' => 'required|integer',
            'items.*.ajustes.*.cantidad'    => 'required|numeric|min:0',
            'items.*.notas'           => 'nullable|string|max:200',
            'descuento_global'        => 'nullable|array',
            'descuento_global.tipo'   => 'required_with:descuento_global|in:porcentaje,monto',
            'descuento_global.valor'  => 'required_with:descuento_global|numeric|min:0',
            'pagos'                   => 'present|array|max:10',
            'pagos.*.metodo_pago_id'  => 'required|integer',
            'pagos.*.monto'           => 'required|numeric|min:0|max:999999',
            'pagos.*.referencia'      => 'nullable|string|max:100',
            'pedido_id'               => 'nullable|integer',
            'cliente_id'              => 'nullable|integer',
            'observaciones'           => 'nullable|string|max:300',
        ], [
            'uuid.required'          => 'Falta el identificador de la venta',
            'items.required'         => 'Agrega al menos un producto',
            'items.min'              => 'Agrega al menos un producto',
            'items.*.cantidad.gt'    => 'Las cantidades deben ser mayores a 0',
            'pagos.present'          => 'Indica la forma de pago',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $venta = VentaService::registrar($validator->validated(), $request->user());

        return response()->json([
            'success' => 1,
            'message' => "Venta {$venta->numero} registrada",
            'venta'   => self::formato($venta),
        ], $venta->wasRecentlyCreated ? 201 : 200);
    }

    // GET /pos/ventas?desde=&hasta=&turno_id=&estado=&usuario_id=&buscar=&page=
    public function index(Request $request)
    {
        $validator = validator($request->all(), [
            'desde'      => 'nullable|date',
            'hasta'      => 'nullable|date|after_or_equal:desde',
            'turno_id'   => 'nullable|integer',
            'usuario_id' => 'nullable|integer',
            'cliente_id' => 'nullable|integer',
            'estado'     => 'nullable|in:completada,anulada,devuelta',
            'buscar'     => 'nullable|string|max:30',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $consulta = Venta::query();

        // Por turno no se filtra por fecha (un turno puede pasar de medianoche);
        // el historial de un cliente sin fechas muestra todas sus compras
        if ($request->filled('turno_id')) {
            $consulta->where('turno_id', $request->turno_id);
        } elseif ($request->filled('cliente_id') && !$request->filled('desde') && !$request->filled('hasta')) {
            $consulta->where('cliente_id', $request->cliente_id);
        } else {
            $hasta = $request->filled('hasta') ? Carbon::parse($request->hasta)->endOfDay() : now()->endOfDay();
            $desde = $request->filled('desde') ? Carbon::parse($request->desde)->startOfDay() : $hasta->copy()->startOfDay();
            $consulta->whereBetween('fecha_venta', [$desde, $hasta]);
        }

        $consulta
            ->when($request->filled('usuario_id'), fn ($q) => $q->where('usuario_id', $request->usuario_id))
            ->when($request->filled('cliente_id'), fn ($q) => $q->where('cliente_id', $request->cliente_id))
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->estado))
            ->when($request->filled('buscar'), fn ($q) => $q->where('numero', 'like', '%' . $request->buscar . '%'));

        $totales = (clone $consulta)->where('estado', '!=', 'anulada')
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM(total), 0) as total')->first();

        $ventas = $consulta
            ->with(['usuario:id,nombre', 'caja:id,nombre', 'pagos.metodoPago:id,nombre', 'cliente:id,nombre'])
            ->withCount('detalles')
            ->orderByDesc('fecha_venta')->orderByDesc('id')
            ->paginate($request->integer('per_page', 25));

        return response()->json([
            'success'       => 1,
            'ventas'        => collect($ventas->items())->map(fn (Venta $v) => [
                'id'        => $v->id,
                'numero'    => $v->numero,
                'fecha'     => $v->fecha_venta->format('Y-m-d H:i'),
                'estado'    => $v->estado,
                'total'     => (float) $v->total,
                'productos' => $v->detalles_count,
                'usuario'   => $v->usuario?->nombre,
                'caja'      => $v->caja?->nombre,
                'turno_id'  => $v->turno_id,
                'cliente'   => $v->cliente?->nombre,
                'pagos'     => $v->pagos->map(fn ($p) => $p->metodoPago?->nombre)->unique()->values(),
            ]),
            'totales'       => [
                'cantidad' => (int) $totales->cantidad,
                'total'    => round((float) $totales->total, 2),
            ],
            'pagina'        => $ventas->currentPage(),
            'total_paginas' => $ventas->lastPage(),
            'total'         => $ventas->total(),
        ]);
    }

    // GET /pos/ventas/{venta}
    public function show(Venta $venta)
    {
        return response()->json(['success' => 1, 'venta' => self::formato($venta)]);
    }

    // POST /pos/ventas/{venta}/anular
    public function anular(Request $request, Venta $venta)
    {
        $validator = validator($request->all(), [
            'motivo'           => 'required|string|max:300',
        ], [
            'motivo.required' => 'Indica el motivo de la anulación',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $venta = VentaService::anular($venta, $request->motivo, $request->user());

        return response()->json([
            'success' => 1,
            'message' => "Venta {$venta->numero} anulada",
            'venta'   => self::formato($venta->fresh()),
        ]);
    }

    // POST /pos/ventas/{venta}/devoluciones
    public function devolver(Request $request, Venta $venta)
    {
        $validator = validator($request->all(), [
            'uuid'                         => 'required|uuid',
            'caja_id'                      => 'required|integer|exists:cajas,id',
            'motivo'                       => 'required|string|max:300',
            'metodo_pago_id'               => 'nullable|integer|exists:metodos_pago,id',
            'items'                        => 'required|array|min:1',
            'items.*.venta_detalle_id'     => 'required|integer',
            'items.*.cantidad'             => 'required|numeric|min:0',
            'items.*.reingresa_inventario' => 'nullable|boolean',
        ], [
            'motivo.required' => 'Indica el motivo de la devolución',
            'items.required'  => 'Indica qué productos se devuelven',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $devolucion = DevolucionService::registrar(
            venta:        $venta,
            items:        $request->items,
            motivo:       $request->motivo,
            cajaId:       (int) $request->caja_id,
            metodoPagoId: $request->metodo_pago_id,
            uuid:         $request->uuid,
            usuario:      $request->user(),
        );

        return response()->json([
            'success'    => 1,
            'message'    => "Devolución {$devolucion->numero} registrada: "
                . ($devolucion->metodoPago?->tipo === 'credito' ? 'se descontaron $' : 'reembolso de $')
                . number_format((float) $devolucion->total, 2)
                . ($devolucion->metodoPago?->tipo === 'credito' ? ' de la cuenta del cliente' : ''),
            'devolucion' => ['id' => $devolucion->id, 'numero' => $devolucion->numero, 'total' => (float) $devolucion->total],
            'venta'      => self::formato($venta->fresh()),
        ], $devolucion->wasRecentlyCreated ? 201 : 200);
    }
}
