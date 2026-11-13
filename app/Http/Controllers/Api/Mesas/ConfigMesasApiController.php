<?php

namespace App\Http\Controllers\Api\Mesas;

use App\Http\Controllers\Controller;
use App\Models\AreaPreparacion;
use App\Models\Categoria;
use App\Models\ConjuntoModificador;
use App\Models\GrupoModificador;
use App\Models\Modificador;
use App\Models\Orden;
use App\Models\Producto;
use App\Models\Terminal;
use App\Models\UnidadesMedida;
use App\Services\AuditoriaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Configuración de mesas: terminales, áreas de preparación y modificadores. */
class ConfigMesasApiController extends Controller
{
    private function errorValidacion($validator)
    {
        return response()->json(['success' => 0, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
    }

    // ── Terminales ────────────────────────────────────────────

    public function terminales()
    {
        return response()->json([
            'success'    => 1,
            'terminales' => Terminal::with('caja:id,nombre')->orderBy('orden')->orderBy('nombre')->get()->map(fn (Terminal $t) => [
                ...$t->only(['id', 'nombre', 'tipo', 'zona', 'capacidad', 'orden', 'caja_id', 'activo']),
                'caja'    => $t->caja?->nombre,
                'ocupada' => Orden::where('terminal_id', $t->id)->where('estado', 'abierta')->exists(),
                // Con alguna orden (abierta, cobrada o anulada) ya no se borra: se desactiva
                'usada'   => Orden::where('terminal_id', $t->id)->exists(),
            ]),
            'zonas'      => Terminal::whereNotNull('zona')->distinct()->orderBy('zona')->pluck('zona'),
        ]);
    }

    public function guardarTerminal(Request $request, ?int $id = null)
    {
        $terminal = $id ? Terminal::findOrFail($id) : null;

        $validator = validator($request->all(), [
            'nombre'    => 'required|string|max:60',
            'tipo'      => 'required|in:mesa,caja',
            'zona'      => 'nullable|string|max:60',
            'capacidad' => 'nullable|integer|min:0|max:99',
            'orden'     => 'nullable|integer|min:0|max:9999',
            'caja_id'   => 'nullable|required_if:tipo,caja|integer|exists:cajas,id',
            'activo'    => 'nullable|boolean',
        ], [
            'nombre.required'     => 'Ponle un nombre a la terminal',
            'caja_id.required_if' => 'Indica qué caja usa la terminal de mostrador',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }
        $datos = $validator->validated();

        if ($terminal && ($datos['activo'] ?? true) === false && Orden::where('terminal_id', $terminal->id)->where('estado', 'abierta')->exists()) {
            return response()->json(['success' => 0, 'message' => 'La terminal tiene una orden abierta: ciérrala antes de desactivarla'], 422);
        }

        // Capacidad vacía (la tablet la manda null): no se toca; una mesa nueva queda con la de la base (4)
        if (array_key_exists('capacidad', $datos) && $datos['capacidad'] === null) {
            unset($datos['capacidad']);
        }

        $antes = $terminal?->toArray();
        // La posición se cambia arrastrando las filas: una terminal nueva va al final
        $terminal ? $terminal->update($datos) : $terminal = Terminal::create([
            ...$datos, 'activo' => $datos['activo'] ?? true, 'orden' => $datos['orden'] ?? (int) Terminal::max('orden') + 1,
        ]);
        AuditoriaService::registrar('mesas', $antes ? 'editar_terminal' : 'crear_terminal', $terminal, $antes, $terminal->toArray());

        return response()->json(['success' => 1, 'message' => "Terminal {$terminal->nombre} guardada"], $antes ? 200 : 201);
    }

    public function actualizarTerminal(Request $request, int $id)
    {
        return $this->guardarTerminal($request, $id);
    }

    // DELETE /mesas/config/terminales/{id}: solo si nunca tuvo una orden (si no, se desactiva)
    public function borrarTerminal(int $id)
    {
        $terminal = Terminal::findOrFail($id);

        if (Orden::where('terminal_id', $terminal->id)->exists()) {
            return response()->json(['success' => 0, 'status' => 'BUSINESS_RULE',
                'message' => "No se puede borrar «{$terminal->nombre}»: ya tiene órdenes. Desactívala en su lugar."], 422);
        }

        $antes = $terminal->toArray();
        $terminal->delete();
        AuditoriaService::registrar('mesas', 'borrar_terminal', $terminal, $antes, null, $terminal->nombre);

        return response()->json(['success' => 1, 'message' => "Terminal {$terminal->nombre} borrada"]);
    }

    // PUT /mesas/config/terminales/orden {ids: [...]} en el orden en que deben aparecer
    public function ordenarTerminales(Request $request)
    {
        $validator = validator($request->all(), [
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer|distinct|exists:terminales,id',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        DB::transaction(function () use ($request) {
            foreach ($request->ids as $n => $id) {
                Terminal::whereKey($id)->update(['orden' => $n + 1]);
            }
        });
        AuditoriaService::registrar('mesas', 'ordenar_terminales', null, null, ['ids' => $request->ids]);

        return response()->json(['success' => 1, 'message' => 'Orden de las terminales guardado']);
    }

    // ── Áreas de preparación y sus categorías ─────────────────

    public function areas()
    {
        return response()->json([
            'success'    => 1,
            'areas'      => AreaPreparacion::orderBy('nombre')->get(['id', 'nombre', 'color', 'impresora_id', 'activo'])
                // Con comandas o productos pedidos ya no se borra: se desactiva
                ->map(fn (AreaPreparacion $a) => [...$a->toArray(), 'usada' => $this->areaUsada($a->id)]),
            'impresoras' => \App\Models\Impresora::where('activo', true)->orderBy('alias')->get(['id', 'alias as nombre', 'uso']),
            'categorias' => Categoria::orderBy('nombre')->get(['id', 'nombre', 'area_preparacion_id']),
        ]);
    }

    public function guardarArea(Request $request, ?int $id = null)
    {
        $area = $id ? AreaPreparacion::findOrFail($id) : null;

        $validator = validator($request->all(), [
            'nombre' => ['required', 'string', 'max:60', Rule::unique('areas_preparacion', 'nombre')->ignore($area?->id)],
            'color'  => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'impresora_id' => 'nullable|integer|exists:impresoras,id',
            'activo' => 'nullable|boolean',
        ], ['nombre.unique' => 'Ya existe un área con ese nombre']);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $area ? $area->update($validator->validated()) : AreaPreparacion::create($validator->validated());

        return response()->json(['success' => 1, 'message' => 'Área guardada'], $area ? 200 : 201);
    }

    public function actualizarArea(Request $request, int $id)
    {
        return $this->guardarArea($request, $id);
    }

    private function areaUsada(int $id): bool
    {
        return DB::table('comandas')->where('area_id', $id)->exists()
            || DB::table('ordenes_items')->where('area_id', $id)->exists();
    }

    // DELETE /mesas/config/areas/{id}: solo si nunca recibió comandas y no tiene categorías
    public function borrarArea(int $id)
    {
        $area = AreaPreparacion::findOrFail($id);

        if ($this->areaUsada($area->id)) {
            return response()->json(['success' => 0, 'status' => 'BUSINESS_RULE',
                'message' => "No se puede borrar «{$area->nombre}»: ya recibió comandas. Desactívala en su lugar."], 422);
        }
        // Sin este aviso sus categorías quedarían en «Sin comanda» sin que nadie lo note
        $categorias = Categoria::where('area_preparacion_id', $area->id)->orderBy('nombre')->pluck('nombre');
        if ($categorias->isNotEmpty()) {
            return response()->json(['success' => 0, 'status' => 'BUSINESS_RULE',
                'message' => "«{$area->nombre}» tiene categorías asignadas ({$categorias->implode(', ')}). Pásalas a otra área (o a «Sin comanda») y guarda antes de borrarla."], 422);
        }

        $antes = $area->toArray();
        $area->delete();
        AuditoriaService::registrar('mesas', 'borrar_area', $area, $antes, null, $area->nombre);

        return response()->json(['success' => 1, 'message' => "Área {$area->nombre} borrada"]);
    }

    // PUT /mesas/config/categorias {asignaciones: [{categoria_id, area_id|null}]}
    public function asignarCategorias(Request $request)
    {
        $validator = validator($request->all(), [
            'asignaciones'                => 'required|array',
            'asignaciones.*.categoria_id' => 'required|integer|exists:categorias,id',
            // Sin área (null): se entrega directo, como el pan de vitrina, y no imprime comanda
            'asignaciones.*.area_id'      => 'present|nullable|integer|exists:areas_preparacion,id',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        DB::transaction(function () use ($request) {
            foreach ($request->asignaciones as $a) {
                Categoria::whereKey($a['categoria_id'])->update(['area_preparacion_id' => $a['area_id']]);
            }
        });
        AuditoriaService::registrar('mesas', 'areas_categorias', null, null, ['asignaciones' => $request->asignaciones]);

        return response()->json(['success' => 1, 'message' => 'Áreas de las categorías guardadas']);
    }

    // ── Modificadores ─────────────────────────────────────────
    // Cada producto tiene sus propios modificadores: su tarjeta (conjunto) → bloques en orden
    // (grupos, con mínimo y máximo) → opciones. Cambiar uno no toca a otro producto.
    // La venta lee los bloques del producto de producto_grupo_modificador.

    private function grupoArray(GrupoModificador $g): array
    {
        return [
            ...$g->only(['id', 'nombre', 'obligatorio', 'multiple', 'minimo', 'maximo', 'activo', 'conjunto_id', 'orden']),
            // «una sola» (exclusiva), obligatoria y activa son de cada opción
            'opciones'  => $g->opciones->map(fn ($o) => [
                'id' => $o->id, 'nombre' => $o->nombre, 'precio' => (float) $o->precio, 'activo' => $o->activo,
                'maximo' => max(1, (int) $o->cantidad_maxima), 'obligatorio' => $o->obligatorio, 'exclusiva' => $o->exclusiva,
                'producto_id' => $o->producto_id, 'insumo' => $o->insumo?->nombre,
                'cantidad' => $o->cantidad !== null ? (float) $o->cantidad : null,
                'unidad_medida_id' => $o->unidad_medida_id, 'unidad' => $o->unidad?->abreviatura,
            ])->values(),
            'productos' => $g->relationLoaded('productos') ? $g->productos->map(fn ($p) => ['id' => $p->id, 'nombre' => $p->nombre]) : [],
        ];
    }

    /** Las opciones quitadas no se muestran; las apagadas sí (se pueden volver a encender). */
    private function conOpciones(string $prefijo = ''): array
    {
        return [
            "{$prefijo}opciones" => fn ($q) => $q->where('eliminada', false),
            "{$prefijo}opciones.insumo:id,nombre",
            "{$prefijo}opciones.unidad:id,abreviatura",
        ];
    }

    public function grupos()
    {
        $conjuntos = ConjuntoModificador::where('activo', true)->whereHas('productos')
            ->with(['bloques', ...$this->conOpciones('bloques.'), 'productos:id,nombre'])->get()
            ->sortBy(fn ($c) => $c->productos->first()?->nombre)->values();
        // Para versiones anteriores de la app, que manejan grupos sueltos
        $grupos = GrupoModificador::where('activo', true)->with([...$this->conOpciones(), 'productos:id,nombre'])->orderBy('nombre')->get();
        $tarjetaDe = DB::table('conjunto_producto')->pluck('conjunto_id', 'producto_id');

        return response()->json([
            'success'   => 1,
            // Un producto con sus modificadores: sus bloques en orden
            'conjuntos' => $conjuntos->map(fn (ConjuntoModificador $c) => $this->tarjetaArray($c)),
            'grupos'    => $grupos->map(fn (GrupoModificador $g) => $this->grupoArray($g)),
            // conjunto_id: sus modificadores, si ya tiene
            'productos' => Producto::where('activo', true)->where('se_vende', true)->orderBy('nombre')->get(['id', 'nombre'])
                ->map(fn ($p) => ['id' => $p->id, 'nombre' => $p->nombre, 'conjunto_id' => $tarjetaDe[$p->id] ?? null]),
            // Lo que puede gastar una opción: productos con inventario, en una unidad de su mismo tipo
            'insumos'   => Producto::with('unidadMedida:id,abreviatura,tipo')->where('activo', true)->where('controla_stock', true)
                ->orderBy('nombre')->get()->map(fn (Producto $p) => [
                    'id' => $p->id, 'nombre' => $p->nombre, 'unidad_medida_id' => $p->unidad_medida_id,
                    'unidad' => $p->unidadMedida?->abreviatura, 'tipo' => $p->unidadMedida?->tipo,
                    'clase' => $p->clase,
                ]),
            'unidades'  => UnidadesMedida::orderBy('tipo')->orderBy('factor_base')->get(['id', 'nombre', 'abreviatura', 'tipo']),
        ]);
    }

    /** Reglas de las opciones de un bloque; $p es el prefijo («opciones» o «bloques.*.opciones»). */
    private function reglasOpciones(string $p): array
    {
        return [
            $p                        => 'required|array|min:1|max:40',
            "{$p}.*.id"               => 'nullable|integer',
            "{$p}.*.nombre"           => 'required|string|max:80',
            "{$p}.*.precio"           => 'nullable|numeric|min:0|max:9999',
            "{$p}.*.maximo"           => 'nullable|integer|min:1|max:20',
            "{$p}.*.obligatorio"      => 'nullable|boolean',
            "{$p}.*.exclusiva"        => 'nullable|boolean',
            "{$p}.*.activo"           => 'nullable|boolean',
            "{$p}.*.producto_id"      => 'nullable|integer|exists:productos,id',
            "{$p}.*.cantidad"         => "nullable|required_with:{$p}.*.producto_id|numeric|gt:0|max:99999",
            "{$p}.*.unidad_medida_id" => "nullable|required_with:{$p}.*.producto_id|integer|exists:unidades_medida,id",
        ];
    }

    private function mensajesOpciones(string $p): array
    {
        return [
            "{$p}.required"                         => 'Agrega al menos una opción',
            "{$p}.*.nombre.required"                => 'Cada opción necesita un nombre',
            "{$p}.*.cantidad.required_with"         => 'Indica cuánto gasta cada opción con insumo',
            "{$p}.*.cantidad.gt"                    => 'La cantidad del insumo debe ser mayor a 0',
            "{$p}.*.unidad_medida_id.required_with" => 'Indica la unidad del insumo de cada opción',
            "{$p}.*.maximo.max"                     => 'Una opción se puede pedir como máximo 20 veces',
        ];
    }

    /**
     * Mínimo y máximo de un bloque (unidades en total, contando repeticiones) y el insumo de cada
     * opción (que lleve inventario y su unidad se pueda convertir).
     */
    private function validarBloque($v, array $b, string $donde): void
    {
        $min = $b['minimo'] ?? null;
        $max = $b['maximo'] ?? null;
        if (is_numeric($min) && is_numeric($max) && (int) $min > (int) $max) {
            $v->errors()->add('minimo', "{$donde}: el mínimo no puede ser mayor que el máximo");
        }
        $opciones = (array) ($b['opciones'] ?? []);
        $alcanzan = collect($opciones)->filter(fn ($o) => !isset($o['activo']) || $o['activo'])->sum(fn ($o) => max(1, (int) ($o['maximo'] ?? 1)));
        if (is_numeric($min) && (int) $min > $alcanzan) {
            $v->errors()->add('minimo', "{$donde}: con esas opciones solo se pueden elegir {$alcanzan}; baja el mínimo o sube el máximo de alguna opción");
        }
        foreach ($opciones as $o) {
            if (empty($o['producto_id']) || empty($o['unidad_medida_id'])) {
                continue;
            }
            $insumo = Producto::with('unidadMedida')->find($o['producto_id']);
            $unidad = UnidadesMedida::find($o['unidad_medida_id']);
            if (!$insumo || !$unidad) {
                continue;
            }
            $opcion = $o['nombre'] ?? 'opción';
            if (!$insumo->controla_stock) {
                $v->errors()->add('opciones', "«{$opcion}»: {$insumo->nombre} no lleva inventario, no se puede descontar");
            } elseif ($insumo->unidadMedida && $unidad->tipo !== $insumo->unidadMedida->tipo) {
                $v->errors()->add('opciones', "«{$opcion}»: {$insumo->nombre} se mide en {$insumo->unidadMedida->nombre}, no en {$unidad->nombre}");
            }
        }
    }

    /** Las opciones que ya no vienen se marcan eliminadas (no se borran: hay órdenes que las citan). */
    private function guardarOpciones(GrupoModificador $grupo, array $opciones): void
    {
        $conservadas = [];
        foreach ($opciones as $n => $o) {
            $opcion = !empty($o['id']) ? Modificador::where('grupo_id', $grupo->id)->find($o['id']) : null;
            $valores = [
                'nombre'           => $o['nombre'],
                'precio'           => round((float) ($o['precio'] ?? 0), 2),
                // Si no viene (versión anterior de la app) se queda como estaba
                'cantidad_maxima'  => isset($o['maximo']) ? (int) $o['maximo'] : ($opcion?->cantidad_maxima ?? 1),
                'producto_id'      => $o['producto_id'] ?? null,
                'cantidad'         => !empty($o['producto_id']) ? $o['cantidad'] : null,
                'unidad_medida_id' => !empty($o['producto_id']) ? $o['unidad_medida_id'] : null,
                'orden'            => $n,
                'obligatorio'      => isset($o['obligatorio']) ? (bool) $o['obligatorio'] : ($opcion?->obligatorio ?? false),
                'exclusiva'        => isset($o['exclusiva']) ? (bool) $o['exclusiva'] : ($opcion?->exclusiva ?? false),
                'activo'           => isset($o['activo']) ? (bool) $o['activo'] : ($opcion?->activo ?? true),
                'eliminada'        => false,
            ];
            $opcion ? $opcion->update($valores) : $opcion = Modificador::create([...$valores, 'grupo_id' => $grupo->id]);
            $conservadas[] = $opcion->id;
        }
        Modificador::where('grupo_id', $grupo->id)->whereNotIn('id', $conservadas)->update(['activo' => false, 'eliminada' => true]);
    }

    /** Lo que usa la venta: cada producto de la tarjeta lleva sus bloques, en orden; los que salieron, ninguno. */
    private function copiarBloquesAProductos(ConjuntoModificador $conjunto): void
    {
        $bloques = $conjunto->bloques()->pluck('id')->values();
        $todos = GrupoModificador::where('conjunto_id', $conjunto->id)->pluck('id');
        $productos = $conjunto->productos()->pluck('productos.id');

        DB::table('producto_grupo_modificador')->whereIn('grupo_id', $todos)->whereNotIn('producto_id', $productos)->delete();
        foreach ($productos as $productoId) {
            Producto::find($productoId)?->gruposModificadores()->sync($bloques->mapWithKeys(fn ($id, $n) => [$id => ['orden' => $n]])->all());
        }
    }

    private function tarjetaArray(ConjuntoModificador $c): array
    {
        $producto = $c->productos->first();

        return [
            'id'          => $c->id,
            'producto_id' => $producto?->id,
            'producto'    => $producto?->nombre,
            'bloques'     => $c->bloques->map(fn ($g) => $this->grupoArray($g))->values(),
        ];
    }

    /**
     * Guarda los bloques de una tarjeta en orden; los que ya no vienen se apagan (hay órdenes que
     * citan sus opciones). Después copia los bloques al producto para la venta.
     */
    private function guardarBloques(ConjuntoModificador $conjunto, array $bloques): void
    {
        $conservados = [];
        foreach ($bloques as $n => $b) {
            $grupo = !empty($b['id']) ? GrupoModificador::where('conjunto_id', $conjunto->id)->find($b['id']) : null;
            $minimo = (int) ($b['minimo'] ?? 0);
            $campos = ['nombre' => $b['nombre'], 'minimo' => $minimo, 'maximo' => isset($b['maximo']) ? (int) $b['maximo'] : null,
                'obligatorio' => $minimo > 0, 'multiple' => true, 'activo' => true, 'conjunto_id' => $conjunto->id, 'orden' => $n];
            $grupo ? $grupo->update($campos) : $grupo = GrupoModificador::create($campos);
            $this->guardarOpciones($grupo, $b['opciones']);
            $conservados[] = $grupo->id;
        }
        GrupoModificador::where('conjunto_id', $conjunto->id)->whereNotIn('id', $conservados)->update(['activo' => false]);
        $this->copiarBloquesAProductos($conjunto);
    }

    /** GET /mesas/config/productos/{id}/modificadores: los modificadores del producto (o null). */
    public function modificadoresProducto(int $id)
    {
        Producto::findOrFail($id);
        $conjunto = ConjuntoModificador::where('activo', true)
            ->whereIn('id', DB::table('conjunto_producto')->where('producto_id', $id)->select('conjunto_id'))
            ->with(['bloques', ...$this->conOpciones('bloques.'), 'productos:id,nombre'])->first();

        return response()->json(['success' => 1, 'conjunto' => $conjunto ? $this->tarjetaArray($conjunto) : null]);
    }

    /**
     * PUT /mesas/config/productos/{id}/modificadores {bloques: [{id?, nombre, minimo, maximo, opciones}]}:
     * los modificadores propios del producto, en bloques. Solo cambian los de este producto.
     */
    public function guardarModificadoresProducto(Request $request, int $id)
    {
        $producto = Producto::findOrFail($id);

        $validator = validator($request->all(), [
            'bloques'          => 'required|array|min:1|max:15',
            'bloques.*.id'     => 'nullable|integer',
            'bloques.*.nombre' => 'required|string|max:80',
            'bloques.*.minimo' => 'nullable|integer|min:0|max:50',
            'bloques.*.maximo' => 'nullable|integer|min:1|max:50',
            ...$this->reglasOpciones('bloques.*.opciones'),
        ], [
            'bloques.required'          => 'Agrega al menos un bloque (ej. Tipo de leche)',
            'bloques.*.nombre.required' => 'Cada bloque necesita un nombre',
            'bloques.*.maximo.min'      => 'El máximo de un bloque debe ser al menos 1',
            ...$this->mensajesOpciones('bloques.*.opciones'),
        ]);
        $validator->after(function ($v) use ($request) {
            foreach ((array) $request->input('bloques', []) as $b) {
                $this->validarBloque($v, (array) $b, '«' . ($b['nombre'] ?? 'Bloque') . '»');
            }
        });
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }
        $datos = $validator->validated();

        $conjunto = null;
        DB::transaction(function () use ($producto, $datos, &$conjunto) {
            $conjuntoId = DB::table('conjunto_producto')->where('producto_id', $producto->id)->value('conjunto_id');
            $conjunto = $conjuntoId ? ConjuntoModificador::find($conjuntoId) : null;
            if ($conjunto) {
                $conjunto->update(['nombre' => mb_substr($producto->nombre, 0, 80), 'activo' => true]);
            } else {
                $conjunto = ConjuntoModificador::create(['nombre' => mb_substr($producto->nombre, 0, 80)]);
                DB::table('conjunto_producto')->insert(['producto_id' => $producto->id, 'conjunto_id' => $conjunto->id]);
            }
            $this->guardarBloques($conjunto, $datos['bloques']);
        });

        AuditoriaService::registrar('mesas', 'guardar_modificadores', $producto, null, [
            'bloques' => collect($datos['bloques'])->map(fn ($b) => $b['nombre'] . ': ' . collect($b['opciones'])->pluck('nombre')->implode(', '))->all(),
        ], $producto->nombre);

        return response()->json(['success' => 1, 'id' => $conjunto->id, 'message' => "Modificadores de «{$producto->nombre}» guardados"]);
    }

    /** DELETE /mesas/config/productos/{id}/modificadores: el producto queda sin modificadores. */
    public function quitarModificadoresProducto(int $id)
    {
        $producto = Producto::findOrFail($id);
        $conjuntoId = DB::table('conjunto_producto')->where('producto_id', $producto->id)->value('conjunto_id');

        DB::transaction(function () use ($producto, $conjuntoId) {
            $producto->gruposModificadores()->sync([]);
            DB::table('conjunto_producto')->where('producto_id', $producto->id)->delete();
            if ($conjuntoId) {
                GrupoModificador::where('conjunto_id', $conjuntoId)->update(['activo' => false]);
                ConjuntoModificador::whereKey($conjuntoId)->update(['activo' => false]);
            }
        });
        AuditoriaService::registrar('mesas', 'quitar_modificadores', $producto, ['conjunto_id' => $conjuntoId], null, $producto->nombre);

        return response()->json(['success' => 1, 'message' => "«{$producto->nombre}» quedó sin modificadores"]);
    }

    /** Versión anterior de la app: un grupo suelto. Si es nuevo, queda como una tarjeta de un bloque. */
    public function guardarGrupo(Request $request, ?int $id = null)
    {
        $grupo = $id ? GrupoModificador::findOrFail($id) : null;

        $validator = validator($request->all(), [
            'nombre'      => 'required|string|max:80',
            'obligatorio' => 'nullable|boolean',
            'multiple'    => 'nullable|boolean',
            'minimo'      => 'nullable|integer|min:0|max:50',
            'maximo'      => 'nullable|integer|min:1|max:50',
            'activo'      => 'nullable|boolean',
            ...$this->reglasOpciones('opciones'),
            'productos'   => 'nullable|array',
            'productos.*' => 'integer|exists:productos,id',
        ], [
            'nombre.required' => 'Ponle un nombre al grupo (ej. Leche, Saborizante)',
            'maximo.min'      => 'El máximo del grupo debe ser al menos 1',
            ...$this->mensajesOpciones('opciones'),
        ]);
        $validator->after(fn ($v) => $this->validarBloque($v, $request->all(), 'El grupo'));
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }
        $datos = $validator->validated();

        DB::transaction(function () use (&$grupo, $datos) {
            // Una versión anterior que solo manda «obligatorio»: el mínimo pasa a 1 (o se queda) y el máximo no cambia
            if (array_key_exists('minimo', $datos)) {
                $minimo = (int) ($datos['minimo'] ?? 0);
                $maximo = isset($datos['maximo']) ? (int) $datos['maximo'] : null;
            } else {
                $obligatorio = (bool) ($datos['obligatorio'] ?? false);
                $minimo = $obligatorio ? max(1, (int) ($grupo?->minimo ?? 1)) : 0;
                $maximo = $grupo?->maximo;
            }
            $campos = ['nombre' => $datos['nombre'], 'obligatorio' => $minimo > 0, 'minimo' => $minimo, 'maximo' => $maximo,
                'multiple' => true, 'activo' => true];
            if (!$grupo) {
                $campos['conjunto_id'] = ConjuntoModificador::create(['nombre' => $datos['nombre']])->id;
            }
            $grupo ? $grupo->update($campos) : $grupo = GrupoModificador::create($campos);
            $this->guardarOpciones($grupo, $datos['opciones']);
            $grupo->productos()->sync($datos['productos'] ?? []);
        });

        AuditoriaService::registrar('mesas', 'guardar_modificadores', $grupo, null, [
            'grupo' => $grupo->nombre, 'opciones' => collect($datos['opciones'])->pluck('nombre')->all(), 'productos' => count($datos['productos'] ?? []),
        ]);

        return response()->json(['success' => 1, 'id' => $grupo->id, 'message' => "Grupo «{$grupo->nombre}» guardado"], $id ? 200 : 201);
    }

    public function actualizarGrupo(Request $request, int $id)
    {
        return $this->guardarGrupo($request, $id);
    }
}
