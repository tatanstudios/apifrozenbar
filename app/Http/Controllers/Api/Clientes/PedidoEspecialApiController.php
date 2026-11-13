<?php

namespace App\Http\Controllers\Api\Clientes;

use App\Http\Controllers\Controller;
use App\Models\Pedido;
use App\Services\PedidoEspecialService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PedidoEspecialApiController extends Controller
{
    private function errorValidacion($validator)
    {
        return response()->json([
            'success' => 0,
            'message' => $validator->errors()->first(),
            'errors'  => $validator->errors(),
        ], 422);
    }

    private function formato(Pedido $p, bool $completo = true): array
    {
        $p->loadMissing(['cliente:id,nombre,telefono', 'usuario:id,nombre', 'venta:id,numero']);

        $datos = [
            'id'            => $p->id,
            'uuid'          => $p->uuid,
            'numero'        => $p->numero,
            'nombre'        => $p->nombre,
            'telefono'      => $p->telefono,
            'cliente_id'    => $p->cliente_id,
            'cliente'       => $p->cliente?->nombre,
            'estado'        => $p->estado,
            'total'         => (float) $p->total,
            'anticipo'      => (float) $p->anticipo,
            'pendiente'     => round((float) $p->total - (float) $p->anticipo, 2),
            'fecha_entrega' => $p->fecha_entrega?->format('Y-m-d H:i'),
            'observaciones' => $p->observaciones,
            'usuario'       => $p->usuario?->nombre,
            'venta'         => $p->venta ? ['id' => $p->venta->id, 'numero' => $p->venta->numero] : null,
            'motivo_cancelacion' => $p->motivo_cancelacion,
            'creado'        => $p->created_at->format('Y-m-d H:i'),
        ];

        if ($completo) {
            $p->loadMissing('detalles.producto:id,precio_venta,activo,se_vende');
            $datos['items'] = $p->detalles->map(fn ($d) => [
                'producto_id'     => $d->producto_id,
                'nombre'          => $d->nombre_producto,
                'cantidad'        => (float) $d->cantidad,
                'precio_unitario' => (float) $d->precio_unitario,
                'precio_actual'   => (float) ($d->producto?->precio_venta ?? $d->precio_unitario),
                'disponible'      => (bool) ($d->producto?->activo && $d->producto?->se_vende),
                'descuento_tipo'  => $d->descuento_tipo,
                'descuento_valor' => (float) $d->descuento_valor,
                'notas'           => $d->notas,
            ]);
            $datos['anticipos'] = PedidoEspecialService::anticipos($p)->map(fn ($m) => [
                'fecha'      => $m->created_at->format('Y-m-d H:i'),
                'tipo'       => $m->tipo,
                'monto'      => (float) $m->monto,
                'metodo'     => $m->metodoPago?->nombre,
                'referencia' => $m->referencia,
            ]);
        }

        return $datos;
    }

    private function reglas(bool $nuevo): array
    {
        return [
            'uuid'                    => $nuevo ? 'required|uuid' : 'nullable',
            'caja_id'                 => 'nullable|integer|exists:cajas,id',
            'cliente_id'              => 'nullable|integer',
            'nombre'                  => 'nullable|string|max:100',
            'telefono'                => 'nullable|string|max:30',
            'fecha_entrega'           => 'required|date',
            'observaciones'           => 'nullable|string|max:500',
            'items'                   => 'required|array|min:1|max:100',
            'items.*.producto_id'     => 'required|integer',
            'items.*.cantidad'        => 'required|numeric|gt:0',
            'items.*.descuento_tipo'  => 'nullable|in:porcentaje,monto',
            'items.*.descuento_valor' => 'nullable|numeric|min:0',
            'items.*.notas'           => 'nullable|string|max:200',
            // Anticipo inicial (solo al crear)
            'anticipo'                => 'nullable|numeric|min:0|max:999999',
            'metodo_pago_id'          => 'nullable|integer|exists:metodos_pago,id',
            'referencia'              => 'nullable|string|max:100',
        ];
    }

    private function mensajes(): array
    {
        return [
            'fecha_entrega.required' => 'Indica la fecha de entrega',
            'items.required'         => 'El pedido no tiene productos',
            'items.min'              => 'El pedido no tiene productos',
        ];
    }

    // GET /pedidos-especiales?estado=abierto|cobrado|cancelado&desde=&hasta=&buscar=
    public function index(Request $request)
    {
        $validator = validator($request->all(), [
            'estado' => 'nullable|in:abierto,cobrado,cancelado,todos',
            'desde'  => 'nullable|date',
            'hasta'  => 'nullable|date|after_or_equal:desde',
            'buscar' => 'nullable|string|max:50',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $estado = $request->input('estado', 'abierto');
        $buscar = trim((string) $request->input('buscar'));

        $pedidos = Pedido::where('tipo', 'especial')
            ->when($estado !== 'todos', fn ($q) => $q->where('estado', $estado))
            ->when($request->filled('desde'), fn ($q) => $q->where('fecha_entrega', '>=', Carbon::parse($request->desde)->startOfDay()))
            ->when($request->filled('hasta'), fn ($q) => $q->where('fecha_entrega', '<=', Carbon::parse($request->hasta)->endOfDay()))
            ->when($buscar !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('numero', 'like', "%{$buscar}%")
                ->orWhere('nombre', 'like', "%{$buscar}%")
                ->orWhere('telefono', 'like', "%{$buscar}%")))
            ->orderBy($estado === 'abierto' ? 'fecha_entrega' : 'id', $estado === 'abierto' ? 'asc' : 'desc')
            ->limit(200)
            ->get();

        return response()->json([
            'success' => 1,
            'pedidos' => $pedidos->map(fn ($p) => $this->formato($p)),
        ]);
    }

    // GET /pedidos-especiales/{pedido}
    public function show(Pedido $pedido)
    {
        abort_if($pedido->tipo !== 'especial', 404);

        return response()->json(['success' => 1, 'pedido' => $this->formato($pedido)]);
    }

    // POST /pedidos-especiales
    public function store(Request $request)
    {
        $validator = validator($request->all(), $this->reglas(true), $this->mensajes());
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $pedido = PedidoEspecialService::guardar($validator->validated(), $request->user());

        return response()->json([
            'success' => 1,
            'message' => "Pedido {$pedido->numero} registrado",
            'pedido'  => $this->formato($pedido->fresh()),
        ], $pedido->wasRecentlyCreated ? 201 : 200);
    }

    // PUT /pedidos-especiales/{pedido}
    public function update(Request $request, Pedido $pedido)
    {
        abort_if($pedido->tipo !== 'especial', 404);

        $validator = validator($request->all(), $this->reglas(false), $this->mensajes());
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $datos = $validator->validated();
        unset($datos['anticipo']); // los anticipos adicionales van por su propia ruta

        $pedido = PedidoEspecialService::guardar($datos, $request->user(), $pedido);

        return response()->json([
            'success' => 1,
            'message' => "Pedido {$pedido->numero} actualizado",
            'pedido'  => $this->formato($pedido->fresh()),
        ]);
    }

    // POST /pedidos-especiales/{pedido}/anticipos
    public function anticipo(Request $request, Pedido $pedido)
    {
        abort_if($pedido->tipo !== 'especial', 404);

        $validator = validator($request->all(), [
            'caja_id'        => 'required|integer|exists:cajas,id',
            'monto'          => 'required|numeric|gt:0|max:999999',
            'metodo_pago_id' => 'nullable|integer|exists:metodos_pago,id',
            'referencia'     => 'nullable|string|max:100',
        ], [
            'monto.required' => 'Indica el monto del anticipo',
            'monto.gt'       => 'El anticipo debe ser mayor a 0',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $pedido = PedidoEspecialService::registrarAnticipo($pedido, (float) $request->monto, $validator->validated(), $request->user());

        return response()->json([
            'success' => 1,
            'message' => 'Anticipo de $' . number_format((float) $request->monto, 2) . ' registrado',
            'pedido'  => $this->formato($pedido->fresh()),
        ]);
    }

    // POST /pedidos-especiales/{pedido}/cancelar
    public function cancelar(Request $request, Pedido $pedido)
    {
        abort_if($pedido->tipo !== 'especial', 404);

        $validator = validator($request->all(), [
            'motivo'            => 'required|string|max:300',
            'devolver_anticipo' => 'nullable|boolean',
            'caja_id'           => 'nullable|integer|exists:cajas,id',
            'metodo_pago_id'    => 'nullable|integer|exists:metodos_pago,id',
        ], [
            'motivo.required' => 'Indica por qué se cancela el pedido',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $pedido = PedidoEspecialService::cancelar(
            $pedido, $request->motivo, $request->boolean('devolver_anticipo'),
            $request->integer('caja_id') ?: null, $request->integer('metodo_pago_id') ?: null, $request->user()
        );

        return response()->json([
            'success' => 1,
            'message' => "Pedido {$pedido->numero} cancelado",
            'pedido'  => $this->formato($pedido->fresh()),
        ]);
    }
}
