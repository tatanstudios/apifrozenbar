<?php

namespace App\Http\Controllers\Api\Productos;

use App\Http\Controllers\Controller;
use App\Models\EntradaInventario;
use App\Models\Inventario;
use App\Models\Producto;
use App\Exceptions\NegocioException;
use App\Services\AuditoriaService;
use App\Services\AutorizacionService;
use App\Services\InventarioService;
use App\Services\NotificacionService;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InventarioApiController extends Controller
{
    // GET /inventario/stock?clase=&categoria_id=&estado=alerta|sin_stock&buscar=
    // Solo productos activos que llevan inventario
    public function stock(Request $request)
    {
        try {
            $productos = Producto::with(['inventario', 'unidadMedida', 'categorias'])
                ->where('activo', true)
                ->where('controla_stock', true)
                ->when($request->filled('clase'), fn ($q) => $q->where('clase', $request->clase))
                ->when($request->filled('categoria_id'), fn ($q) =>
                    $q->whereHas('categorias', fn ($c) => $c->where('categorias.id', $request->categoria_id)))
                ->when($request->filled('buscar'), function ($q) use ($request) {
                    $texto = '%' . $request->buscar . '%';
                    $q->where(fn ($w) => $w->where('nombre', 'like', $texto)
                        ->orWhere('sku', 'like', $texto)
                        ->orWhere('codigo_barras', 'like', $texto));
                })
                ->orderBy('nombre')
                ->get()
                ->map(function ($p) {
                    $stock  = (float) ($p->inventario->stock_actual ?? 0);
                    $minimo = (float) ($p->inventario->stock_minimo ?? 0);
                    $costo  = (float) $p->costo_unitario;

                    return [
                        'producto_id'      => $p->id,
                        'producto'         => $p->nombre,
                        'sku'              => $p->sku,
                        'codigo_barras'    => $p->codigo_barras,
                        'imagen'           => $p->imagen,
                        'clase'            => $p->clase,
                        'categorias'       => $p->categorias->pluck('nombre')->toArray(),
                        'unidad'           => $p->unidadMedida?->abreviatura ?? $p->unidadMedida?->nombre,
                        'permite_fraccion' => $p->permite_fraccion,
                        'costo_unitario'   => $costo,
                        'precio_venta'     => (float) $p->precio_venta,
                        'valor_inventario' => round(max($stock, 0) * $costo, 2),
                        'stock_actual'     => $stock,
                        'stock_minimo'     => $minimo,
                        'bajo_minimo'      => InventarioService::enAlerta($stock, $minimo),
                    ];
                });

            if ($request->estado === 'alerta') {
                $productos = $productos->where('bajo_minimo', true)->values();
            } elseif ($request->estado === 'sin_stock') {
                $productos = $productos->filter(fn ($p) => $p['stock_actual'] <= 0)->values();
            }

            return response()->json($productos);

        } catch (\Exception $e) {
            Log::error('Error al obtener stock: ' . $e->getMessage());

            return response()->json([
                'success' => 0,
                'message' => 'Error al obtener stock',
            ], 500);
        }
    }

    // PUT /inventario/stock-minimo/{producto}
    public function actualizarStockMinimo(Request $request, Producto $producto)
    {
        $validator = validator($request->all(), [
            'stock_minimo' => 'required|numeric|min:0',
        ], [
            'stock_minimo.required' => 'Indica el stock mínimo',
            'stock_minimo.min'      => 'El stock mínimo no puede ser negativo',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $inventario = Inventario::firstOrCreate(
            ['producto_id' => $producto->id],
            ['stock_actual' => 0, 'stock_minimo' => 0]
        );

        $antes = (float) $inventario->stock_minimo;
        $inventario->stock_minimo = $request->stock_minimo;
        $inventario->save();

        // Subir el mínimo puede dejar al producto en alerta sin que se mueva el stock
        $stock = (float) $inventario->stock_actual;
        NotificacionService::revisarStock($producto, $stock, $stock, (float) $inventario->stock_minimo, $antes);

        AuditoriaService::registrar('inventario', 'stock_minimo', $producto,
            ['stock_minimo' => $antes], ['stock_minimo' => (float) $inventario->stock_minimo], $producto->nombre);

        return response()->json([
            'success'     => 1,
            'message'     => 'Stock mínimo actualizado',
            'bajo_minimo' => InventarioService::enAlerta((float) $inventario->stock_actual, (float) $inventario->stock_minimo),
        ]);
    }

    // GET /inventario/alertas — productos en o bajo su mínimo, o en negativo
    public function alertas()
    {
        $productos = collect(InventarioService::productosEnAlerta());

        return response()->json([
            'success'   => 1,
            'total'     => $productos->count(),
            'productos' => $productos->take(50)->values(),
        ]);
    }

    // GET /inventario/entradas
    public function listarEntradas()
    {
        try {
            $entradas = EntradaInventario::with(['usuario', 'autorizadoPor:id,nombre', 'detalles.producto', 'turno.usuarioApertura:id,nombre', 'turno.usuarioCierre:id,nombre'])
                ->orderByDesc('fecha')
                ->orderByDesc('id')
                ->get()
                ->map(fn($e) => [
                    'id'          => $e->id,
                    'fecha'       => $e->fecha->format('d-m-Y'),
                    'tipo'        => $e->tipo,
                    'descripcion' => $e->descripcion,
                    'usuario'     => $e->usuario?->nombre,
                    'turno'       => $e->turno?->etiqueta(),
                    'autorizado_por' => $e->autorizadoPor?->nombre,
                    'total_items' => $e->detalles->count(),
                    'detalles'    => $e->detalles->map(fn($d) => [
                        'producto_id'      => $d->producto_id,
                        'producto'         => $d->producto->nombre,
                        'sku'              => $d->producto->sku,
                        'cantidad'         => (float) $d->cantidad,
                        'stock_anterior'   => (float) $d->stock_anterior,
                        'stock_resultante' => (float) $d->stock_resultante,
                    ]),
                ]);

            return response()->json($entradas);

        } catch (\Exception $e) {
            Log::error('Error al obtener entradas: ' . $e->getMessage());

            return response()->json([
                'success' => 0,
                'message' => 'Error al obtener entradas',
            ], 500);
        }
    }

    // POST /inventario/entradas/registrar
    public function registrarEntrada(Request $request)
    {
        $validator = validator($request->all(), [
            'fecha'                  => 'required|date|before_or_equal:today',
            'tipo'                   => 'required|in:entrada,salida,ajuste,produccion',
            'descripcion'            => 'nullable|required_if:tipo,ajuste|string|max:500',
            'items'                  => 'required|array|min:1',
            'items.*.producto_id'    => 'required|integer|distinct|exists:productos,id',
            // En "ajuste" la cantidad es el conteo físico, por eso puede ser 0
            'items.*.cantidad'       => 'required|numeric|min:0',
            'items.*.costo_unitario' => 'nullable|numeric|min:0',
        ], [
            'fecha.required'               => 'La fecha es requerida',
            'fecha.before_or_equal'        => 'La fecha no puede ser futura',
            'tipo.required'                => 'El tipo es requerido',
            'tipo.in'                      => 'Tipo no válido',
            'descripcion.required_if'      => 'Indica el motivo del ajuste',
            'items.required'               => 'Debes agregar al menos un producto',
            'items.min'                    => 'Debes agregar al menos un producto',
            'items.*.producto_id.required' => 'Cada línea debe tener un producto',
            'items.*.producto_id.distinct' => 'Hay productos repetidos en el desglose',
            'items.*.producto_id.exists'   => 'Uno o más productos no existen',
            'items.*.cantidad.required'    => 'Cada línea debe tener una cantidad',
            'items.*.cantidad.min'         => 'La cantidad no puede ser negativa',
            'items.*.costo_unitario.min'   => 'El costo no puede ser negativo',
        ]);

        $validator->after(function ($v) use ($request) {
            if ($request->input('tipo') === 'ajuste') {
                return;
            }
            foreach ((array) $request->input('items', []) as $i => $item) {
                if (is_numeric($item['cantidad'] ?? null) && (float) $item['cantidad'] <= 0) {
                    $v->errors()->add("items.{$i}.cantidad", 'La cantidad debe ser mayor a 0');
                }
            }
        });

        // Los ajustes (fijar el stock a una cantidad) requieren un permiso aparte.
        // Se revisa antes de validar para no dar detalles a quien no puede hacerlo
        $permisoRequerido = $request->input('tipo') === 'ajuste' ? 'inventario.ajustar' : 'inventario.registrar';
        if (!$request->user()->can($permisoRequerido)) {
            return response()->json([
                'success' => 0,
                'message' => 'No tienes permiso para registrar este tipo de movimiento',
            ], 403);
        }

        if ($validator->fails()) {
            return response()->json([
                'success' => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Los insumos de receta solo bajan con las ventas y la producción: bajarlos a mano se autoriza
        $autorizador = null;
        $bajas = InventarioService::bajasDeInsumos($request->input('tipo'), $request->input('items'));
        if ($bajas) {
            $autorizador = AutorizacionService::autorizar($request->user(), 'inventario.autorizar_baja',
                'Bajar a mano ' . implode(', ', $bajas) . ' necesita autorización del administrador: estos insumos se descuentan con las ventas y la producción');
        }

        try {
            $entrada = InventarioService::registrarEntrada(
                tipo:          $request->input('tipo'),
                fecha:         $request->input('fecha'),
                items:         $request->input('items'),
                descripcion:   $request->input('descripcion'),
                usuarioId:     auth()->id(),
                autorizadoPor: $autorizador?->id,
            );

            AuditoriaService::registrar('inventario', $entrada->tipo, $entrada, null, [
                'fecha' => $request->input('fecha'),
                'items' => $request->input('items'),
                'autorizado_por' => $autorizador?->nombre,
                'insumos_a_la_baja' => $bajas ?: null,
            ], $entrada->descripcion);

            return response()->json([
                'success' => 1,
                'message' => 'Registro guardado correctamente',
                'id'      => $entrada->id,
            ]);

        } catch (NegocioException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error al registrar el movimiento: ' . $e->getMessage());

            return response()->json([
                'success' => 0,
                'message' => 'Error al registrar el movimiento',
            ], 500);
        }
    }

    // GET /inventario/entradas/{id}
    public function mostrarEntrada($id)
    {
        try {
            $entrada = EntradaInventario::with(['usuario', 'autorizadoPor:id,nombre', 'detalles.producto', 'turno.usuarioApertura:id,nombre', 'turno.usuarioCierre:id,nombre'])->find($id);

            if (!$entrada) {
                return response()->json(['success' => 0, 'message' => 'Registro no encontrado'], 404);
            }

            return response()->json([
                'id'          => $entrada->id,
                'fecha'       => $entrada->fecha->format('d-m-Y'),
                'tipo'        => $entrada->tipo,
                'descripcion' => $entrada->descripcion,
                'usuario'     => $entrada->usuario?->nombre,
                'turno'       => $entrada->turno?->etiqueta(),
                'autorizado_por' => $entrada->autorizadoPor?->nombre,
                'detalles'    => $entrada->detalles->map(fn($d) => [
                    'producto_id'      => $d->producto_id,
                    'producto'         => $d->producto->nombre,
                    'sku'              => $d->producto->sku,
                    'imagen'           => $d->producto->imagen,
                    'cantidad'         => (float) $d->cantidad,
                    'stock_anterior'   => (float) $d->stock_anterior,
                    'stock_resultante' => (float) $d->stock_resultante,
                ]),
            ]);

        } catch (\Exception $e) {
            Log::error('Error al obtener registro: ' . $e->getMessage());

            return response()->json([
                'success' => 0,
                'message' => 'Error al obtener registro',
            ], 500);
        }
    }
}
