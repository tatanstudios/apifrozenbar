<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Models\Pedido;
use App\Services\PedidoService;
use Illuminate\Http\Request;

class PedidoApiController extends Controller
{
    private function formato(Pedido $p): array
    {
        $p->loadMissing(['detalles.producto:id,precio_venta,activo,se_vende', 'usuario:id,nombre']);

        return [
            'id'            => $p->id,
            'uuid'          => $p->uuid,
            'numero'        => $p->numero,
            'nombre'        => $p->nombre,
            'estado'        => $p->estado,
            'total'         => (float) $p->total,
            'observaciones' => $p->observaciones,
            'usuario'       => $p->usuario?->nombre,
            'turno'         => $p->turno?->etiqueta(),
            'creado'        => $p->created_at->format('Y-m-d H:i'),
            'items'         => $p->detalles->map(fn ($d) => [
                'producto_id'     => $d->producto_id,
                'nombre'          => $d->nombre_producto,
                'cantidad'        => (float) $d->cantidad,
                'precio_unitario' => (float) $d->precio_unitario,
                'precio_actual'   => (float) ($d->producto?->precio_venta ?? $d->precio_unitario),
                'disponible'      => (bool) ($d->producto?->activo && $d->producto?->se_vende),
                'descuento_tipo'  => $d->descuento_tipo,
                'descuento_valor' => (float) $d->descuento_valor,
                'notas'           => $d->notas,
            ]),
        ];
    }

    private function reglas(bool $nuevo): array
    {
        return [
            'uuid'                    => $nuevo ? 'required|uuid' : 'nullable',
            'caja_id'                 => 'nullable|integer|exists:cajas,id',
            'nombre'                  => 'required|string|max:100',
            'observaciones'           => 'nullable|string|max:500',
            'items'                   => 'required|array|min:1|max:200',
            'items.*.producto_id'     => 'required|integer',
            'items.*.cantidad'        => 'required|numeric|gt:0',
            'items.*.descuento_tipo'  => 'nullable|in:porcentaje,monto',
            'items.*.descuento_valor' => 'nullable|numeric|min:0',
            'items.*.notas'           => 'nullable|string|max:200',
        ];
    }

    private function mensajes(): array
    {
        return [
            'nombre.required' => 'Ponle un nombre a la precuenta (ej. Mesa 2 o el nombre del cliente)',
            'items.required'  => 'La precuenta no tiene productos',
        ];
    }

    private function errorValidacion($validator)
    {
        return response()->json([
            'success' => 0,
            'message' => $validator->errors()->first(),
            'errors'  => $validator->errors(),
        ], 422);
    }

    // GET /pos/pedidos?estado=abierto
    public function index(Request $request)
    {
        $pedidos = Pedido::where('tipo', 'precuenta')
            ->where('estado', $request->input('estado', 'abierto'))
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json(['success' => 1, 'pedidos' => $pedidos->map(fn ($p) => $this->formato($p))]);
    }

    // POST /pos/pedidos
    public function store(Request $request)
    {
        $validator = validator($request->all(), $this->reglas(true), $this->mensajes());
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $pedido = PedidoService::guardar($validator->validated(), $request->user());

        return response()->json([
            'success' => 1,
            'message' => "Precuenta {$pedido->numero} guardada",
            'pedido'  => $this->formato($pedido),
        ], $pedido->wasRecentlyCreated ? 201 : 200);
    }

    // PUT /pos/pedidos/{pedido}
    public function update(Request $request, Pedido $pedido)
    {
        $validator = validator($request->all(), $this->reglas(false), $this->mensajes());
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $pedido = PedidoService::guardar($validator->validated(), $request->user(), $pedido);

        return response()->json([
            'success' => 1,
            'message' => "Precuenta {$pedido->numero} actualizada",
            'pedido'  => $this->formato($pedido->fresh()),
        ]);
    }

    // POST /pos/pedidos/{pedido}/cancelar
    public function cancelar(Request $request, Pedido $pedido)
    {
        $validator = validator($request->all(), ['motivo' => 'required|string|max:300'], [
            'motivo.required' => 'Indica por qué se cancela la precuenta',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        PedidoService::cancelar($pedido, $request->motivo, $request->user());

        return response()->json(['success' => 1, 'message' => 'Precuenta cancelada']);
    }
}
