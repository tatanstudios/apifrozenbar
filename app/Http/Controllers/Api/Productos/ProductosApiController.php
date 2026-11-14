<?php

namespace App\Http\Controllers\Api\Productos;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Models\Inventario;
use App\Services\AuditoriaService;
use App\Services\InventarioService;
use App\Services\NotificacionService;
use Illuminate\Support\Facades\Log;

class ProductosApiController extends Controller
{
    // ── Validación común de registro y edición ────────────────────
    private function reglas(?int $id = null): array
    {
        return [
            'sku'                 => 'nullable|string|max:100|unique:productos,sku' . ($id ? ",{$id}" : ''),
            'codigo_barras'       => 'nullable|string|max:50|unique:productos,codigo_barras' . ($id ? ",{$id}" : ''),
            'nombre'              => 'required|string|max:300',
            'descripcion'         => 'nullable|string',
            'imagen'              => 'nullable|image|mimes:jpeg,png,jpg|max:5120',
            'tipo'                => 'nullable|in:bien,servicio,bien_servicio',
            'clase'               => ['required', Rule::in(array_keys(Producto::CLASES))],
            'se_vende'            => 'required|boolean',
            'consumo_interno'     => 'nullable|boolean',
            'controla_stock'      => 'required|boolean',
            'permite_fraccion'    => 'required|boolean',
            'permite_sin_existencia' => 'nullable|boolean',
            'imprime_comanda'     => 'nullable|boolean',
            'costo_unitario'      => 'required|numeric|min:0',
            'precio_venta'        => 'required|numeric|min:0',
            'stock_minimo'        => 'nullable|numeric|min:0',
            'orden_pos'           => 'nullable|integer|min:0',
            'color_pos'           => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'unidad_medida_id'    => 'required|integer|exists:unidades_medida,id',
            'activo'              => 'nullable|boolean',
            'categorias'          => 'nullable|array',
            'categorias.*'        => 'integer|exists:categorias,id',
        ];
    }

    private function mensajes(): array
    {
        return [
            'nombre.required'           => 'El nombre es requerido',
            'clase.required'            => 'La clase de producto es requerida',
            'clase.in'                  => 'La clase de producto no es válida',
            'costo_unitario.required'   => 'El costo unitario es requerido',
            'costo_unitario.numeric'    => 'El costo unitario debe ser numérico',
            'precio_venta.required'     => 'El precio de venta es requerido',
            'precio_venta.numeric'      => 'El precio de venta debe ser numérico',
            'stock_minimo.numeric'      => 'El stock mínimo debe ser numérico',
            'color_pos.regex'           => 'El color debe tener el formato #RRGGBB',
            'unidad_medida_id.required' => 'La unidad de medida es requerida',
            'unidad_medida_id.exists'   => 'La unidad de medida no existe',
            'sku.unique'                => 'El SKU ya está registrado',
            'codigo_barras.unique'      => 'El código de barras ya está registrado en otro producto',
            'imagen.image'              => 'El archivo debe ser una imagen válida',
            'imagen.mimes'              => 'La imagen debe ser JPG o PNG',
            'imagen.max'                => 'La imagen debe pesar menos de 5MB',
            'categorias.*.exists'       => 'Una o más categorías no existen',
        ];
    }

    // Campos del producto tomados del request (sin imagen ni categorías)
    private function datosProducto(Request $request): array
    {
        $controlaStock = $request->boolean('controla_stock');
        // Cena del personal, cortesías: se "venden" a $0.00 para descontar su receta sin tocar la caja
        $consumoInterno = $request->boolean('consumo_interno');

        return [
            'codigo_barras'    => $request->filled('codigo_barras') ? trim($request->input('codigo_barras')) : null,
            'nombre'           => $request->input('nombre'),
            'descripcion'      => $request->input('descripcion'),
            // "tipo" se conserva por compatibilidad: se deriva de si lleva inventario
            'tipo'             => $request->input('tipo') ?: ($controlaStock ? Producto::TIPO_BIEN : Producto::TIPO_SERVICIO),
            'clase'            => $request->input('clase'),
            'se_vende'         => $consumoInterno || $request->boolean('se_vende'),
            'consumo_interno'  => $consumoInterno,
            'controla_stock'   => $controlaStock,
            'permite_fraccion' => $request->boolean('permite_fraccion'),
            'costo_unitario'   => $request->input('costo_unitario', 0),
            'precio_venta'     => $consumoInterno ? 0 : $request->input('precio_venta', 0),
            'orden_pos'        => (int) $request->input('orden_pos', 0),
            'color_pos'        => $request->input('color_pos') ?: null,
            'unidad_medida_id' => $request->input('unidad_medida_id'),
        ]
            // Si no viene (versión anterior de la app) se queda como estaba; al crear, encendido
            + ($request->has('imprime_comanda') ? ['imprime_comanda' => $request->boolean('imprime_comanda')] : [])
            // Solo tiene sentido si lleva inventario (Coca-Cola): se vende aunque marque 0. Si no viene
            // (versión anterior de la app) se queda como estaba, salvo que deje de llevar inventario
            + ($request->has('permite_sin_existencia') || !$controlaStock
                ? ['permite_sin_existencia' => $controlaStock && $request->boolean('permite_sin_existencia')] : []);
    }

    private function errorValidacion($validator)
    {
        return response()->json([
            'success' => 0,
            'message' => $validator->errors()->first(),
            'errors'  => $validator->errors(),
        ], 422);
    }

    public function registrarProducto(Request $request)
    {
        // ── Validación ─────────────────────────────────────────
        $validator = validator($request->all(), $this->reglas(), $this->mensajes());

        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        // ── Guardado transaccional ─────────────────────────────
        DB::beginTransaction();
        try {

            // 1) Guardar la imagen (directa, sin procesar)
            $nombreImagen = null;
            if ($request->hasFile('imagen')) {
                $foto         = $request->file('imagen');
                $nombreImagen = time() . '_' . Str::random(15) . '.' . $foto->getClientOriginalExtension();
                $foto->move(Storage::disk('archivos')->path(''), $nombreImagen);
            }

            // 2) Crear el producto
            $producto = new Producto($this->datosProducto($request));
            $producto->sku    = $request->input('sku') ?: $this->generarSku();
            $producto->imagen = $nombreImagen;
            $producto->activo = $request->input('activo', true);
            $producto->save();

            // 3) Asignar categorías (tabla categoria_producto)
            $categorias = $request->input('categorias', []);
            if (!empty($categorias)) {
                $producto->categorias()->sync($categorias);
            }

            // 4) Fila de inventario en cero (la bloquea InventarioService al mover stock)
            Inventario::firstOrCreate(
                ['producto_id' => $producto->id],
                ['stock_actual' => 0, 'stock_minimo' => $request->input('stock_minimo', 0) ?: 0]
            );

            DB::commit();

            AuditoriaService::registrar('productos', 'crear', $producto, null, $producto->toArray() + ['categorias' => $categorias]);

            return response()->json([
                'success' => 1,
                'message' => 'Producto registrado correctamente',
                'id'      => $producto->id,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            // Limpieza: si falló después de subir la imagen, la eliminamos
            if (!empty($nombreImagen) && Storage::disk('archivos')->exists($nombreImagen)) {
                Storage::disk('archivos')->delete($nombreImagen);
            }

            Log::error('Error al registrar el producto: ' . $e->getMessage());

            return response()->json([
                'success' => 0,
                'message' => 'Error al registrar el producto',
            ], 500);
        }
    }

    private function generarSku(): string
    {
        do {
            $sku = 'PRD-' . strtoupper(Str::random(8));
        } while (Producto::where('sku', $sku)->exists());

        return $sku;
    }


    // ── Orden de los botones del POS ──────────────────────────────

    // Separación entre posiciones: deja espacio para meter uno en medio sin renumerar
    private const PASO_ORDEN = 10;

    /**
     * GET /productos/orden-pos: los productos a la venta en el orden del POS,
     * con sus categorías, para ordenarlos arrastrando y para ubicar uno nuevo.
     */
    public function ordenPos()
    {
        $productos = Producto::with('categorias:id')
            ->where('activo', true)->where('se_vende', true)
            ->orderBy('orden_pos')->orderBy('nombre')
            ->get(['id', 'nombre', 'precio_venta', 'imagen', 'color_pos', 'orden_pos']);

        return response()->json([
            'success'   => 1,
            'paso'      => self::PASO_ORDEN,
            'categorias' => Categoria::where('estado', true)->orderBy('nombre')->get(['id', 'nombre']),
            'productos' => $productos->map(fn (Producto $p) => [
                'id'         => $p->id,
                'nombre'     => $p->nombre,
                'precio'     => (float) $p->precio_venta,
                'imagen'     => $p->imagen,
                'color_pos'  => $p->color_pos,
                'orden_pos'  => (int) $p->orden_pos,
                'categorias' => $p->categorias->pluck('id'),
            ]),
        ]);
    }

    /**
     * PUT /productos/orden-pos {ids: [...]} en el orden en que deben aparecer.
     * Reparte las posiciones que ya ocupaban esos productos (o 10, 20, 30... si
     * estaban empatados), así reordenar una categoría no los mueve respecto de las demás.
     */
    public function ordenarPos(Request $request)
    {
        $validator = validator($request->all(), [
            'ids'   => 'required|array|min:1|max:500',
            'ids.*' => 'integer|distinct|exists:productos,id',
        ], ['ids.required' => 'No hay productos para ordenar']);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }
        $ids = array_map('intval', $request->ids);

        DB::transaction(function () use ($ids) {
            $posiciones = Producto::whereIn('id', $ids)->pluck('orden_pos')->map(fn ($o) => (int) $o)->sort()->values();
            // Si había empates (todos en 0), se numeran de nuevo después del mayor que ya existía
            if ($posiciones->unique()->count() < count($ids)) {
                $inicio = $posiciones->first() ?: 0;
                $posiciones = collect(range(1, count($ids)))->map(fn ($n) => $inicio + $n * self::PASO_ORDEN);
            }
            foreach ($ids as $n => $id) {
                Producto::whereKey($id)->update(['orden_pos' => $posiciones[$n]]);
            }
        });
        AuditoriaService::registrar('productos', 'ordenar_pos', null, null, ['ids' => $ids]);

        return response()->json(['success' => 1, 'message' => 'Orden del POS guardado']);
    }

    // Dónde puede aparecer un producto. Con cualquiera de estos ya no se borra (queda historial
    // o configuración que lo usa); categorías, extras asignados, stock en 0 y avisos se van con él
    private const USOS_PRODUCTO = [
        'movimientos_inventario'      => 'movimiento(s) de inventario',
        'entradas_inventario_detalle' => 'entrada(s) de inventario',
        'ventas_detalle'              => 'venta(s)',
        'devoluciones_detalle'        => 'devolución(es)',
        'pedidos_detalle'             => 'pedido(s)',
        'ordenes_items'               => 'consumo(s) en mesas',
        'compras_detalle'             => 'compra(s)',
        'ordenes_compra_detalle'      => 'orden(es) de compra',
        'recetas'                     => 'receta(s) propia(s)',
        'recetas_ingredientes'        => 'receta(s) donde es ingrediente',
        'producciones'                => 'producción(es)',
        'producciones_consumos'       => 'consumo(s) de producción',
        'mermas'                      => 'merma(s)',
        'modificadores'               => 'extra(s) que lo descuentan',
    ];

    private function usosProducto(int $id): array
    {
        $usos = [];
        foreach (self::USOS_PRODUCTO as $tabla => $texto) {
            if ($n = DB::table($tabla)->where('producto_id', $id)->count()) {
                $usos[] = "{$n} {$texto}";
            }
        }
        return $usos;
    }

    // DELETE /productos/{id}: solo si nunca se usó (si no, se desactiva)
    public function borrarProducto(int $id)
    {
        $producto = Producto::with('categorias')->find($id);
        if (!$producto) {
            return response()->json(['success' => 0, 'message' => 'El producto no existe'], 404);
        }

        $usos = $this->usosProducto($producto->id);
        $stock = (float) (Inventario::where('producto_id', $producto->id)->value('stock_actual') ?? 0);
        if ($usos || abs($stock) > 0.0001) {
            $detalle = $usos ? 'aparece en ' . implode(', ', $usos) : 'tiene existencia';
            return response()->json([
                'success' => 0,
                'status'  => 'BUSINESS_RULE',
                'message' => "No se puede borrar «{$producto->nombre}»: {$detalle}. Desactívalo en su lugar.",
            ], 422);
        }

        $antes = $producto->toArray() + ['categorias' => $producto->categorias->pluck('nombre')->all()];
        // Nunca se usó: se borra de verdad (libera el SKU y el código de barras); categorías,
        // stock en 0, extras asignados y avisos se van por cascada
        DB::transaction(fn () => $producto->forceDelete());
        if ($producto->imagen && Storage::disk('archivos')->exists($producto->imagen)) {
            Storage::disk('archivos')->delete($producto->imagen);
        }
        AuditoriaService::registrar('productos', 'borrar', $producto, $antes, null, $producto->nombre);

        return response()->json(['success' => 1, 'message' => 'Producto borrado']);
    }

    public function tablaProductos()
    {
        try {
            // usado: con true el botón Borrar no aparece
            $usados = collect(array_keys(self::USOS_PRODUCTO))
                ->flatMap(fn ($t) => DB::table($t)->whereNotNull('producto_id')->distinct()->pluck('producto_id'))
                ->unique()->flip();

            $productos = Producto::with(['unidadMedida', 'categorias', 'inventario'])
                ->orderByDesc('id')
                ->get()
                ->map(function ($producto) use ($usados) {
                    return [
                        'usado'          => $usados->has($producto->id) || abs((float) ($producto->inventario->stock_actual ?? 0)) > 0.0001,
                        'id'             => $producto->id,
                        'sku'            => $producto->sku,
                        'codigo_barras'  => $producto->codigo_barras,
                        'nombre'         => $producto->nombre,
                        'descripcion'    => $producto->descripcion,
                        'imagen'         => $producto->imagen,
                        'color_pos'      => $producto->color_pos,
                        'tipo'           => $producto->tipo,
                        'clase'          => $producto->clase,
                        'se_vende'       => $producto->se_vende,
                        'consumo_interno' => $producto->consumo_interno,
                        'controla_stock' => $producto->controla_stock,
                        'costo_unitario' => $producto->costo_unitario,
                        'precio_venta'   => $producto->precio_venta,
                        'stock_actual'   => $producto->controla_stock ? (float) ($producto->inventario->stock_actual ?? 0) : null,
                        'activo'         => (bool) $producto->activo,
                        'unidad_medida'  => $producto->unidadMedida?->nombre,
                        'unidad_abrev'   => $producto->unidadMedida?->abreviatura,
                        'categorias'     => $producto->categorias->pluck('nombre')->toArray(),
                    ];
                });

            return response()->json($productos);

        } catch (\Exception $e) {
            Log::error('Error al obtener los productos: ' . $e->getMessage());

            return response()->json([
                'success' => 0,
                'message' => 'Error al obtener los productos',
            ], 500);
        }
    }


    public function mostrarProducto($id)
    {
        try {
            $producto = Producto::with(['unidadMedida', 'categorias', 'inventario'])->find($id);

            if (!$producto) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Producto no encontrado',
                ], 404);
            }

            return response()->json([
                'id'               => $producto->id,
                'sku'              => $producto->sku,
                'codigo_barras'    => $producto->codigo_barras,
                'nombre'           => $producto->nombre,
                'descripcion'      => $producto->descripcion,
                'imagen'           => $producto->imagen,
                'tipo'             => $producto->tipo,
                'clase'            => $producto->clase,
                'se_vende'         => $producto->se_vende,
                'consumo_interno'  => $producto->consumo_interno,
                'controla_stock'   => $producto->controla_stock,
                'permite_fraccion' => $producto->permite_fraccion,
                'permite_sin_existencia' => $producto->permite_sin_existencia,
                'imprime_comanda'  => $producto->imprime_comanda,
                'costo_unitario'   => $producto->costo_unitario,
                'precio_venta'     => $producto->precio_venta,
                'stock_actual'     => (float) ($producto->inventario->stock_actual ?? 0),
                'stock_minimo'     => (float) ($producto->inventario->stock_minimo ?? 0),
                'orden_pos'        => $producto->orden_pos,
                'color_pos'        => $producto->color_pos,
                'unidad_medida_id' => $producto->unidad_medida_id,
                'activo'           => (bool) $producto->activo,
                'categorias'       => $producto->categorias->pluck('id')->toArray(),
            ]);

        } catch (\Exception $e) {
            Log::error('Error al obtener el producto: ' . $e->getMessage());

            return response()->json([
                'success' => 0,
                'message' => 'Error al obtener el producto',
            ], 500);
        }
    }


    public function actualizarProducto(Request $request, $id)
    {
        $producto = Producto::with('inventario')->find($id);

        if (!$producto) {
            return response()->json([
                'success' => 0,
                'message' => 'Producto no encontrado',
            ], 404);
        }

        // ── Validación ─────────────────────────────────────────
        $validator = validator($request->all(), $this->reglas((int) $id), $this->mensajes());

        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        // Cambiar precio o costo requiere un permiso aparte (el dueño fija los precios)
        $cambiaPrecio = round((float) $request->input('precio_venta'), 2) !== round((float) $producto->precio_venta, 2);
        $cambiaCosto  = round((float) $request->input('costo_unitario'), 4) !== round((float) $producto->costo_unitario, 4);

        if (($cambiaPrecio || $cambiaCosto) && !$request->user()->can('productos.precios')) {
            return response()->json([
                'success' => 0,
                'message' => 'No tienes permiso para cambiar precios ni costos',
            ], 403);
        }

        DB::beginTransaction();
        try {

            $antes = $producto->toArray() + ['categorias' => $producto->categorias()->pluck('categorias.id')->toArray()];

            // 1) Si viene nueva imagen, guardarla y borrar la anterior
            $imagenAnterior = $producto->imagen;
            $nombreImagen   = $producto->imagen;

            if ($request->hasFile('imagen')) {
                $foto         = $request->file('imagen');
                $nombreImagen = time() . '_' . Str::random(15) . '.' . $foto->getClientOriginalExtension();
                $foto->move(Storage::disk('archivos')->path(''), $nombreImagen);
            }

            // 2) Actualizar el producto
            $producto->fill($this->datosProducto($request));
            $producto->sku    = $request->input('sku') ?: $producto->sku;
            $producto->imagen = $nombreImagen;

            if ($request->has('activo')) {
                $producto->activo = filter_var($request->input('activo'), FILTER_VALIDATE_BOOLEAN);
            }

            $producto->save();

            // Vende sin existencia: sus avisos de stock pendientes ya no aplican
            if ($producto->permite_sin_existencia) {
                NotificacionService::limpiarStock($producto);
            }

            // 3) Sincronizar categorías
            $producto->categorias()->sync($request->input('categorias', []));

            // 4) Stock mínimo (vive en la fila de inventario)
            if ($request->has('stock_minimo')) {
                Inventario::updateOrCreate(
                    ['producto_id' => $producto->id],
                    ['stock_minimo' => $request->input('stock_minimo') ?: 0]
                );
            }

            DB::commit();

            // 5) Borrar imagen anterior solo si se reemplazó correctamente
            if ($request->hasFile('imagen') && $imagenAnterior && Storage::disk('archivos')->exists($imagenAnterior)) {
                Storage::disk('archivos')->delete($imagenAnterior);
            }

            AuditoriaService::registrar('productos', 'actualizar', $producto, $antes,
                $producto->fresh()->toArray() + ['categorias' => $request->input('categorias', [])]);

            // Los cambios de precio y costo quedan además como evento propio para buscarlos fácil
            if ($cambiaPrecio || $cambiaCosto) {
                AuditoriaService::registrar('productos', 'cambio_precio', $producto,
                    ['precio_venta' => $antes['precio_venta'], 'costo_unitario' => $antes['costo_unitario']],
                    ['precio_venta' => $producto->precio_venta, 'costo_unitario' => $producto->costo_unitario],
                    $producto->nombre);
            }

            return response()->json([
                'success' => 1,
                'message' => 'Producto actualizado correctamente',
                'id'      => $producto->id,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            // Limpieza: si subimos imagen nueva pero falló, eliminarla
            if ($request->hasFile('imagen') && !empty($nombreImagen) && $nombreImagen !== $imagenAnterior
                && Storage::disk('archivos')->exists($nombreImagen)) {
                Storage::disk('archivos')->delete($nombreImagen);
            }

            Log::error('Error al actualizar el producto: ' . $e->getMessage());

            return response()->json([
                'success' => 0,
                'message' => 'Error al actualizar el producto',
            ], 500);
        }
    }
}
