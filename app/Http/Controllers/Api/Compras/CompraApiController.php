<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Models\Compra;
use App\Models\CompraDetalle;
use App\Models\CuentaPagar;
use App\Models\NotaCreditoProveedor;
use App\Models\PagoProveedor;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\UnidadesMedida;
use App\Services\CompraService;
use App\Services\CuentaPagarService;
use Illuminate\Http\Request;

class CompraApiController extends Controller
{
    private function errorValidacion($validator)
    {
        return response()->json([
            'success' => 0,
            'message' => $validator->errors()->first(),
            'errors'  => $validator->errors(),
        ], 422);
    }

    public static function formato(Compra $c, bool $completo = true): array
    {
        $c->loadMissing(['proveedor:id,nombre', 'usuario:id,nombre', 'orden:id,numero', 'cuentaPagar']);

        $datos = [
            'id'               => $c->id,
            'uuid'             => $c->uuid,
            'numero'           => $c->numero,
            'proveedor_id'     => $c->proveedor_id,
            'proveedor'        => $c->proveedor?->nombre,
            'orden'            => $c->orden?->numero,
            'fecha'            => $c->fecha->toDateString(),
            'tipo_documento'   => $c->tipo_documento,
            'numero_documento' => $c->numero_documento,
            'condicion'        => $c->condicion,
            'subtotal'         => (float) $c->subtotal,
            'impuesto'         => (float) $c->impuesto,
            'total'            => (float) $c->total,
            'saldo'            => (float) ($c->cuentaPagar?->saldo ?? 0),
            'fecha_vencimiento' => $c->cuentaPagar?->fecha_vencimiento?->toDateString(),
            'estado'           => $c->estado,
            'observaciones'    => $c->observaciones,
            'usuario'          => $c->usuario?->nombre,
            'turno'            => $c->turno?->etiqueta(),
            'motivo_anulacion' => $c->motivo_anulacion,
        ];

        if ($completo) {
            $c->loadMissing(['detalles.producto:id,nombre,unidad_medida_id', 'detalles.unidad:id,nombre,abreviatura', 'detalles.producto.unidadMedida:id,abreviatura', 'anuladaPor:id,nombre']);
            $datos['anulada_por'] = $c->anuladaPor?->nombre;
            $datos['items'] = $c->detalles->map(fn ($d) => [
                'producto_id'    => $d->producto_id,
                'producto'       => $d->producto?->nombre,
                'unidad'         => $d->unidad?->abreviatura ?? $d->unidad?->nombre,
                'cantidad'       => (float) $d->cantidad,
                'costo_unitario' => (float) $d->costo_unitario,
                'subtotal'       => (float) $d->subtotal,
                'cantidad_base'  => (float) $d->cantidad_base,
                'unidad_base'    => $d->producto?->unidadMedida?->abreviatura,
                'costo_base'     => (float) $d->costo_base,
            ]);
            $datos['pagos'] = $c->cuentaPagar
                ? PagoProveedor::with('metodoPago:id,nombre')
                    ->whereHas('detalles', fn ($q) => $q->where('cuenta_pagar_id', $c->cuentaPagar->id))
                    ->orderBy('id')->get()->map(fn ($p) => self::formatoPago($p))
                : [];
        }

        return $datos;
    }

    public static function formatoPago(PagoProveedor $p): array
    {
        $p->loadMissing(['metodoPago:id,nombre', 'usuario:id,nombre', 'proveedor:id,nombre', 'detalles.cuenta.compra:id,numero']);

        return [
            'id'               => $p->id,
            'numero'           => $p->numero,
            'proveedor_id'     => $p->proveedor_id,
            'proveedor'        => $p->proveedor?->nombre,
            'fecha'            => $p->fecha->toDateString(),
            'monto'            => (float) $p->monto,
            'origen'           => $p->origen,
            'turno_id'         => $p->turno_id,
            'metodo'           => $p->metodoPago?->nombre,
            'referencia'       => $p->referencia,
            'usuario'          => $p->usuario?->nombre,
            'compras'          => $p->detalles->map(fn ($d) => $d->cuenta?->compra?->numero)->filter()->values(),
            'anulado'          => $p->anulado,
            'motivo_anulacion' => $p->motivo_anulacion,
        ];
    }

    // GET /compras/catalogo: productos que se compran (incluida materia prima) y unidades
    public function catalogo()
    {
        $productos = Producto::with('unidadMedida:id,nombre,abreviatura,tipo')
            ->where('activo', true)->orderBy('nombre')
            ->get(['id', 'nombre', 'sku', 'clase', 'unidad_medida_id', 'costo_unitario', 'controla_stock', 'permite_fraccion']);

        return response()->json([
            'success'   => 1,
            'productos' => $productos->map(fn ($p) => [
                'id'               => $p->id,
                'nombre'           => $p->nombre,
                'sku'              => $p->sku,
                'clase'            => $p->clase,
                'unidad_medida_id' => $p->unidad_medida_id,
                'unidad'           => $p->unidadMedida?->abreviatura ?? $p->unidadMedida?->nombre,
                'unidad_tipo'      => $p->unidadMedida?->tipo,
                'costo_unitario'   => (float) $p->costo_unitario,
                'controla_stock'   => $p->controla_stock,
                'permite_fraccion' => $p->permite_fraccion,
            ]),
            'unidades'  => UnidadesMedida::where('estado', true)->orderBy('tipo')->orderBy('factor_base')
                ->get(['id', 'nombre', 'abreviatura', 'tipo', 'factor_base'])
                ->map(fn ($u) => [...$u->only(['id', 'nombre', 'abreviatura', 'tipo']), 'factor_base' => (float) $u->factor_base]),
        ]);
    }

    // GET /compras?desde=&hasta=&proveedor_id=&producto_id=&estado=&condicion=&buscar=
    public function index(Request $request)
    {
        $validator = validator($request->all(), [
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date|after_or_equal:desde',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $consulta = Compra::query()
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('fecha', '>=', $request->desde))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('fecha', '<=', $request->hasta))
            ->when($request->filled('proveedor_id'), fn ($q) => $q->where('proveedor_id', $request->proveedor_id))
            ->when($request->filled('producto_id'), fn ($q) => $q->whereHas('detalles', fn ($d) => $d->where('producto_id', $request->producto_id)))
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->estado))
            ->when($request->filled('condicion'), fn ($q) => $q->where('condicion', $request->condicion))
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($w) => $w
                ->where('numero', 'like', "%{$request->buscar}%")
                ->orWhere('numero_documento', 'like', "%{$request->buscar}%")));

        $total = (clone $consulta)->where('estado', 'registrada')->sum('total');

        $compras = $consulta->orderByDesc('fecha')->orderByDesc('id')->paginate(min($request->integer('per_page', 25), 100));

        return response()->json([
            'success'       => 1,
            'compras'       => collect($compras->items())->map(fn ($c) => self::formato($c, false)),
            'totales'       => ['total' => round((float) $total, 2)],
            'pagina'        => $compras->currentPage(),
            'total_paginas' => $compras->lastPage(),
            'total'         => $compras->total(),
        ]);
    }

    public function show(Compra $compra)
    {
        return response()->json(['success' => 1, 'compra' => self::formato($compra)]);
    }

    // POST /compras
    public function store(Request $request)
    {
        $validator = validator($request->all(), [
            'uuid'                     => 'required|uuid',
            'proveedor_id'             => 'required|integer|exists:proveedores,id',
            'orden_compra_id'          => 'nullable|integer',
            'fecha'                    => 'required|date',
            'tipo_documento'           => 'required|in:factura,ccf,ticket,otro',
            'numero_documento'         => 'nullable|string|max:50',
            'condicion'                => 'required|in:contado,credito',
            'fecha_vencimiento'        => 'nullable|date|after_or_equal:fecha',
            'impuesto'                 => 'nullable|numeric|min:0|max:999999',
            'observaciones'            => 'nullable|string|max:500',
            'items'                    => 'required|array|min:1|max:200',
            'items.*.producto_id'      => 'required|integer',
            'items.*.unidad_medida_id' => 'nullable|integer',
            'items.*.cantidad'         => 'required|numeric|gt:0',
            'items.*.costo_unitario'   => 'required|numeric|min:0',
            'items.*.orden_detalle_id' => 'nullable|integer',
            'pago'                     => 'required_if:condicion,contado|array',
            'pago.origen'              => 'required_if:condicion,contado|in:caja,externo',
            'pago.caja_id'             => 'nullable|integer|exists:cajas,id',
            'pago.metodo_pago_id'      => 'nullable|integer|exists:metodos_pago,id',
            'pago.referencia'          => 'nullable|string|max:100',
        ], [
            'proveedor_id.required'    => 'Elige el proveedor',
            'fecha.required'           => 'Indica la fecha de la compra',
            'condicion.required'       => 'Indica si es de contado o al crédito',
            'items.required'           => 'Agrega al menos un producto',
            'items.*.cantidad.gt'      => 'Las cantidades deben ser mayores a 0',
            'pago.required_if'         => 'Indica cómo se pagó la compra de contado',
            'pago.origen.required_if'  => 'Indica si se pagó desde la caja o fuera de caja',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        // De contado el pago forma parte de la misma recepción: basta el permiso de compras
        $compra = CompraService::registrar($validator->validated(), $request->user());

        return response()->json([
            'success' => 1,
            'message' => "Compra {$compra->numero} registrada",
            'compra'  => self::formato($compra->fresh()),
        ], $compra->wasRecentlyCreated ? 201 : 200);
    }

    // POST /compras/{compra}/anular
    public function anular(Request $request, Compra $compra)
    {
        $validator = validator($request->all(), ['motivo' => 'required|string|max:300'], ['motivo.required' => 'Indica el motivo de la anulación']);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $compra = CompraService::anular($compra, $request->motivo, $request->user());

        return response()->json(['success' => 1, 'message' => "Compra {$compra->numero} anulada", 'compra' => self::formato($compra->fresh())]);
    }

    // GET /compras/historial-producto/{producto}: costos de compra a lo largo del tiempo
    public function historialProducto(Producto $producto)
    {
        $lineas = CompraDetalle::with(['compra:id,numero,fecha,proveedor_id,estado', 'compra.proveedor:id,nombre', 'unidad:id,abreviatura'])
            ->where('producto_id', $producto->id)
            ->whereHas('compra', fn ($q) => $q->where('estado', 'registrada'))
            ->orderByDesc('id')->limit(50)->get();

        return response()->json([
            'success'  => 1,
            'producto' => ['id' => $producto->id, 'nombre' => $producto->nombre, 'costo_promedio' => (float) $producto->costo_unitario],
            'compras'  => $lineas->map(fn ($l) => [
                'compra_id'      => $l->compra_id,
                'compra'         => $l->compra?->numero,
                'fecha'          => $l->compra?->fecha?->toDateString(),
                'proveedor'      => $l->compra?->proveedor?->nombre,
                'cantidad'       => (float) $l->cantidad,
                'unidad'         => $l->unidad?->abreviatura,
                'costo_unitario' => (float) $l->costo_unitario,
                'costo_base'     => (float) $l->costo_base,
            ]),
        ]);
    }

    // ── Cuentas por pagar ─────────────────────────────────────

    // GET /cuentas-pagar?estado=pendiente|vencidas|pagada&proveedor_id=
    public function cuentas(Request $request)
    {
        $estado = $request->input('estado', 'pendiente');

        $consulta = CuentaPagar::with('proveedor:id,nombre')
            ->when($estado === 'vencidas', fn ($q) => $q->where('estado', 'pendiente')->whereDate('fecha_vencimiento', '<', today()))
            ->when(in_array($estado, ['pendiente', 'pagada', 'anulada'], true), fn ($q) => $q->where('estado', $estado))
            ->when($request->filled('proveedor_id'), fn ($q) => $q->where('proveedor_id', $request->proveedor_id));

        $pendiente = CuentaPagar::where('estado', 'pendiente')->get(['saldo', 'fecha_vencimiento']);
        $semana = today()->addDays(7);

        $cuentas = $consulta->orderBy('fecha_vencimiento')->orderBy('id')->paginate(min($request->integer('per_page', 25), 100));

        return response()->json([
            'success'       => 1,
            'cuentas'       => collect($cuentas->items())->map(fn ($c) => [
                ...CuentaPagarService::formatoCuenta($c),
                'proveedor_id' => $c->proveedor_id,
                'proveedor'    => $c->proveedor?->nombre,
            ]),
            'resumen'       => [
                'total'          => round($pendiente->sum('saldo'), 2),
                'vencido'        => round($pendiente->filter(fn ($c) => $c->fecha_vencimiento->lt(today()))->sum('saldo'), 2),
                'proxima_semana' => round($pendiente->filter(fn ($c) => $c->fecha_vencimiento->between(today(), $semana))->sum('saldo'), 2),
                'proveedores'    => Proveedor::where('saldo', '>', 0)->count(),
            ],
            'pagina'        => $cuentas->currentPage(),
            'total_paginas' => $cuentas->lastPage(),
            'total'         => $cuentas->total(),
        ]);
    }

    // GET /cuentas-pagar/pagos?desde=&hasta=&proveedor_id=
    public function pagos(Request $request)
    {
        $pagos = PagoProveedor::query()
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('fecha', '>=', $request->desde))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('fecha', '<=', $request->hasta))
            ->when($request->filled('proveedor_id'), fn ($q) => $q->where('proveedor_id', $request->proveedor_id))
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 25), 100));

        return response()->json([
            'success'       => 1,
            'pagos'         => collect($pagos->items())->map(fn ($p) => self::formatoPago($p)),
            'pagina'        => $pagos->currentPage(),
            'total_paginas' => $pagos->lastPage(),
            'total'         => $pagos->total(),
        ]);
    }

    // POST /cuentas-pagar/proveedores/{proveedor}/pagos
    public function registrarPago(Request $request, Proveedor $proveedor)
    {
        $validator = validator($request->all(), [
            'uuid'                          => 'required|uuid',
            'monto'                         => 'required|numeric|gt:0|max:9999999',
            'fecha'                         => 'nullable|date|before_or_equal:today',
            'origen'                        => 'required|in:caja,externo',
            'caja_id'                       => 'required_if:origen,caja|nullable|integer|exists:cajas,id',
            'metodo_pago_id'                => 'nullable|integer|exists:metodos_pago,id',
            'referencia'                    => 'nullable|string|max:100',
            'aplicaciones'                  => 'nullable|array',
            'aplicaciones.*.cuenta_pagar_id' => 'required|integer',
            'aplicaciones.*.monto'          => 'required|numeric|gt:0',
        ], [
            'monto.required'      => 'Indica el monto del pago',
            'origen.required'     => 'Indica si se paga desde la caja o fuera de caja',
            'caja_id.required_if' => 'Indica la caja de la que sale el pago',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $datos = $validator->validated();
        if (!empty($datos['aplicaciones'])) {
            $suma = round(collect($datos['aplicaciones'])->sum('monto'), 2);
            if (abs($suma - round((float) $datos['monto'], 2)) > 0.001) {
                return response()->json(['success' => 0, 'message' => 'La suma aplicada a las compras no coincide con el monto del pago'], 422);
            }
        }

        $pago = CuentaPagarService::registrarPago($proveedor, $datos, $request->user());

        return response()->json([
            'success'   => 1,
            'message'   => "Pago {$pago->numero} de $" . number_format((float) $pago->monto, 2) . ' registrado',
            'pago'      => self::formatoPago($pago),
            'proveedor' => ProveedorApiController::formato($proveedor->fresh()),
        ], $pago->wasRecentlyCreated ? 201 : 200);
    }

    // POST /cuentas-pagar/pagos/{pago}/anular
    public function anularPago(Request $request, PagoProveedor $pago)
    {
        $validator = validator($request->all(), ['motivo' => 'required|string|max:300'], ['motivo.required' => 'Indica el motivo de la anulación']);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $pago = CuentaPagarService::anularPago($pago, $request->motivo, $request->user());

        return response()->json([
            'success'   => 1,
            'message'   => "Pago {$pago->numero} anulado",
            'proveedor' => ProveedorApiController::formato($pago->proveedor->fresh()),
        ]);
    }

    // POST /cuentas-pagar/{cuenta}/notas-credito {monto, motivo, documento?}: rebaja lo que se debe sin mover caja
    public function notaCredito(Request $request, CuentaPagar $cuenta)
    {
        $validator = validator($request->all(), [
            'monto'     => 'required|numeric|min:0.01',
            'motivo'    => 'required|string|max:300',
            'documento' => 'nullable|string|max:50',
        ], [
            'monto.required'  => 'Escribe el monto a rebajar',
            'monto.min'       => 'El monto debe ser mayor a 0',
            'motivo.required' => 'Explica por qué se rebaja',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $nota = CuentaPagarService::registrarNotaCredito($cuenta, (float) $request->monto, trim($request->motivo), $request->documento, $request->user());

        return response()->json([
            'success' => 1,
            'message' => "Nota {$nota->numero} registrada: se rebajó $" . number_format((float) $nota->monto, 2) . ' de lo que se debe',
            'cuenta'  => CuentaPagarService::formatoCuenta($cuenta->fresh()),
        ]);
    }

    // POST /cuentas-pagar/notas-credito/{nota}/anular {motivo}
    public function anularNotaCredito(Request $request, NotaCreditoProveedor $nota)
    {
        $validator = validator($request->all(), ['motivo' => 'required|string|max:300'], ['motivo.required' => 'Indica el motivo de la anulación']);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $nota = CuentaPagarService::anularNotaCredito($nota, trim($request->motivo), $request->user());

        return response()->json([
            'success' => 1,
            'message' => "Nota {$nota->numero} anulada: el monto vuelve a deberse",
            'cuenta'  => CuentaPagarService::formatoCuenta($nota->cuenta->fresh()),
        ]);
    }
}
