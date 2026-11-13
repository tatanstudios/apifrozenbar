<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Models\ConfiguracionNegocio;
use App\Models\OrdenCompra;
use App\Services\CompraService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class OrdenCompraApiController extends Controller
{
    private function errorValidacion($validator)
    {
        return response()->json([
            'success' => 0,
            'message' => $validator->errors()->first(),
            'errors'  => $validator->errors(),
        ], 422);
    }

    public static function formato(OrdenCompra $o, bool $completo = true): array
    {
        $o->loadMissing(['proveedor:id,nombre,telefono,correo', 'usuario:id,nombre']);

        $datos = [
            'id'             => $o->id,
            'numero'         => $o->numero,
            'proveedor_id'   => $o->proveedor_id,
            'proveedor'      => $o->proveedor?->nombre,
            'fecha'          => $o->fecha->toDateString(),
            'fecha_esperada' => $o->fecha_esperada?->toDateString(),
            'estado'         => $o->estado,
            'total'          => (float) $o->total,
            'observaciones'  => $o->observaciones,
            'usuario'        => $o->usuario?->nombre,
            'turno'          => $o->turno?->etiqueta(),
            'motivo_cancelacion' => $o->motivo_cancelacion,
        ];

        if ($completo) {
            $o->loadMissing(['detalles.producto:id,nombre', 'detalles.unidad:id,nombre,abreviatura', 'compras:id,orden_compra_id,numero,fecha,total,estado']);
            $datos['items'] = $o->detalles->map(fn ($d) => [
                'id'                => $d->id,
                'producto_id'       => $d->producto_id,
                'producto'          => $d->producto?->nombre,
                'unidad_medida_id'  => $d->unidad_medida_id,
                'unidad'            => $d->unidad?->abreviatura ?? $d->unidad?->nombre,
                'cantidad'          => (float) $d->cantidad,
                'cantidad_recibida' => (float) $d->cantidad_recibida,
                'pendiente'         => $d->pendiente(),
                'costo_unitario'    => (float) $d->costo_unitario,
                'subtotal'          => (float) $d->subtotal,
            ]);
            $datos['compras'] = $o->compras->map(fn ($c) => [
                'id' => $c->id, 'numero' => $c->numero, 'fecha' => $c->fecha->toDateString(), 'total' => (float) $c->total, 'estado' => $c->estado,
            ]);
        }

        return $datos;
    }

    // GET /compras/ordenes?estado=&proveedor_id=
    public function index(Request $request)
    {
        $ordenes = OrdenCompra::query()
            ->when($request->filled('estado'), fn ($q) => $q->whereIn('estado', explode(',', $request->estado)))
            ->when($request->filled('proveedor_id'), fn ($q) => $q->where('proveedor_id', $request->proveedor_id))
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 25), 100));

        return response()->json([
            'success'       => 1,
            'ordenes'       => collect($ordenes->items())->map(fn ($o) => self::formato($o, false)),
            'pagina'        => $ordenes->currentPage(),
            'total_paginas' => $ordenes->lastPage(),
            'total'         => $ordenes->total(),
        ]);
    }

    public function show(OrdenCompra $orden)
    {
        return response()->json(['success' => 1, 'orden' => self::formato($orden)]);
    }

    private function validar(Request $request)
    {
        return validator($request->all(), [
            'proveedor_id'             => 'required|integer|exists:proveedores,id',
            'fecha'                    => 'nullable|date',
            'fecha_esperada'           => 'nullable|date|after_or_equal:fecha',
            'observaciones'            => 'nullable|string|max:500',
            'items'                    => 'required|array|min:1|max:200',
            'items.*.producto_id'      => 'required|integer|distinct',
            'items.*.unidad_medida_id' => 'nullable|integer',
            'items.*.cantidad'         => 'required|numeric|gt:0',
            'items.*.costo_unitario'   => 'required|numeric|min:0',
        ], [
            'proveedor_id.required'       => 'Elige el proveedor',
            'items.required'              => 'Agrega al menos un producto',
            'items.*.producto_id.distinct' => 'Hay productos repetidos en la orden',
            'items.*.cantidad.gt'         => 'Las cantidades deben ser mayores a 0',
            'fecha_esperada.after_or_equal' => 'La fecha esperada no puede ser anterior a la de la orden',
        ]);
    }

    public function store(Request $request)
    {
        $validator = $this->validar($request);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $orden = CompraService::guardarOrden($validator->validated(), $request->user());

        return response()->json(['success' => 1, 'message' => "Orden {$orden->numero} guardada", 'orden' => self::formato($orden->fresh())], 201);
    }

    public function update(Request $request, OrdenCompra $orden)
    {
        $validator = $this->validar($request);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $orden = CompraService::guardarOrden($validator->validated(), $request->user(), $orden);

        return response()->json(['success' => 1, 'message' => "Orden {$orden->numero} actualizada", 'orden' => self::formato($orden->fresh())]);
    }

    // POST /compras/ordenes/{orden}/{accion}: enviar | cancelar | cerrar
    public function accion(Request $request, OrdenCompra $orden, string $accion)
    {
        $validator = validator($request->all(), [
            'motivo' => $accion === 'enviar' ? 'nullable|string|max:300' : 'required|string|max:300',
        ], ['motivo.required' => 'Indica el motivo']);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $orden = CompraService::cambiarEstadoOrden($orden, $accion, $request->user(), $request->motivo);
        $textos = ['enviar' => 'marcada como enviada', 'cancelar' => 'cancelada', 'cerrar' => 'cerrada'];

        return response()->json(['success' => 1, 'message' => "Orden {$orden->numero} {$textos[$accion]}", 'orden' => self::formato($orden->fresh())]);
    }

    // GET /compras/ordenes/{orden}/pdf: para enviar al proveedor
    public function pdf(OrdenCompra $orden)
    {
        return Pdf::loadView('pdf.orden-compra', [
            'negocio' => ConfiguracionNegocio::actual(),
            'orden'   => $orden->load(['proveedor', 'usuario', 'detalles.producto', 'detalles.unidad']),
        ])->setPaper('letter')->setOption('isFontSubsettingEnabled', true)
            ->download("orden-compra-{$orden->numero}.pdf");
    }
}
