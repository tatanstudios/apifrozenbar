<?php

namespace App\Http\Controllers\Api\Produccion;

use App\Http\Controllers\Controller;
use App\Models\Merma;
use App\Models\Produccion;
use App\Models\Producto;
use App\Models\Receta;
use App\Models\UnidadesMedida;
use App\Services\ProduccionService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ProduccionApiController extends Controller
{
    private function errorValidacion($validator)
    {
        return response()->json([
            'success' => 0,
            'message' => $validator->errors()->first(),
            'errors'  => $validator->errors(),
        ], 422);
    }

    // GET /produccion/catalogo: productos con existencia y costo, y unidades
    public function catalogo()
    {
        $productos = Producto::with(['unidadMedida:id,nombre,abreviatura,tipo', 'inventario:producto_id,stock_actual'])
            ->where('activo', true)->orderBy('nombre')->get();

        return response()->json([
            'success'   => 1,
            'productos' => $productos->map(fn (Producto $p) => [
                'id'               => $p->id,
                'nombre'           => $p->nombre,
                'clase'            => $p->clase,
                'se_vende'         => $p->se_vende,
                'unidad_medida_id' => $p->unidad_medida_id,
                'unidad'           => $p->unidadMedida?->abreviatura ?? $p->unidadMedida?->nombre,
                'unidad_tipo'      => $p->unidadMedida?->tipo,
                'costo_unitario'   => (float) $p->costo_unitario,
                'precio_venta'     => (float) $p->precio_venta,
                'controla_stock'   => $p->controla_stock,
                'consumo_interno'  => $p->consumo_interno,
                'permite_fraccion' => $p->permite_fraccion,
                'stock'            => $p->controla_stock ? (float) ($p->inventario?->stock_actual ?? 0) : null,
            ]),
            'unidades'  => UnidadesMedida::where('estado', true)->orderBy('tipo')->orderBy('factor_base')
                ->get(['id', 'nombre', 'abreviatura', 'tipo', 'factor_base'])
                ->map(fn ($u) => [...$u->only(['id', 'nombre', 'abreviatura', 'tipo']), 'factor_base' => (float) $u->factor_base]),
            'motivos'   => Merma::MOTIVOS,
        ]);
    }

    // ── Recetas ───────────────────────────────────────────────

    private function formatoReceta(Receta $r, bool $completo = true): array
    {
        $r->loadMissing(['producto:id,nombre,unidad_medida_id,precio_venta,costo_unitario', 'producto.unidadMedida:id,abreviatura', 'usuario:id,nombre']);
        $costeo = ProduccionService::costear($r);
        $precio = (float) $r->producto?->precio_venta;

        $datos = [
            'id'             => $r->id,
            'producto_id'    => $r->producto_id,
            'producto'       => $r->producto?->nombre,
            'unidad'         => $r->producto?->unidadMedida?->abreviatura,
            'nombre'         => $r->nombre,
            'rendimiento'    => (float) $r->rendimiento,
            'uso'            => $r->uso,
            'version'        => $r->version,
            'activa'         => $r->activa,
            'notas'          => $r->notas,
            'usuario'        => $r->usuario?->nombre,
            'actualizada'    => $r->updated_at->format('Y-m-d H:i'),
            'costo_total'    => $costeo['costo_total'],
            'costo_unitario' => $costeo['costo_unitario'],
            'precio_venta'   => $precio,
            // Margen sobre el precio de venta con el costo de hoy
            'margen'         => $precio > 0 ? round(($precio - $costeo['costo_unitario']) / $precio * 100, 1) : null,
        ];

        if ($completo) {
            $ve = $this->veIngredientes();
            $datos['ingredientes_ocultos'] = !$ve;
            $datos['ingredientes'] = $ve ? $costeo['ingredientes'] : [];
            if (!$ve) {
                $datos['notas'] = null;
            }
            $datos['versiones'] = Receta::where('producto_id', $r->producto_id)->orderByDesc('version')
                ->get(['id', 'version', 'activa', 'updated_at'])
                ->map(fn ($v) => ['id' => $v->id, 'version' => $v->version, 'activa' => $v->activa, 'actualizada' => $v->updated_at->format('Y-m-d')]);
        }

        return $datos;
    }

    // GET /produccion/recetas?buscar=&todas=1
    public function recetas(Request $request)
    {
        $recetas = Receta::query()
            ->when(!$request->boolean('todas'), fn ($q) => $q->where('activa', true))
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($w) => $w
                ->where('nombre', 'like', "%{$request->buscar}%")
                ->orWhereHas('producto', fn ($p) => $p->where('nombre', 'like', "%{$request->buscar}%"))))
            ->orderBy('nombre')->get();

        return response()->json(['success' => 1, 'recetas' => $recetas->map(fn ($r) => $this->formatoReceta($r, false))]);
    }

    public function receta(Receta $receta)
    {
        return response()->json(['success' => 1, 'receta' => $this->formatoReceta($receta)]);
    }

    private function validarReceta(Request $request)
    {
        return validator($request->all(), [
            'producto_id'                     => 'required|integer|exists:productos,id',
            'nombre'                          => 'nullable|string|max:150',
            'rendimiento'                     => 'required|numeric|gt:0|max:99999',
            'notas'                           => 'nullable|string|max:1000',
            'ingredientes'                    => 'required|array|min:1|max:60',
            'ingredientes.*.producto_id'      => 'required|integer',
            'ingredientes.*.unidad_medida_id' => 'required|integer',
            'ingredientes.*.cantidad'         => 'required|numeric|gt:0',
            'ingredientes.*.merma_porcentaje' => 'nullable|numeric|min:0|max:100',
        ], [
            'producto_id.required'          => 'Elige el producto que produce la receta',
            'rendimiento.required'          => 'Indica cuánto rinde la receta',
            'rendimiento.gt'                => 'El rendimiento debe ser mayor a 0',
            'ingredientes.required'         => 'Agrega al menos un ingrediente',
            'ingredientes.*.cantidad.gt'    => 'Las cantidades de los ingredientes deben ser mayores a 0',
            'ingredientes.*.unidad_medida_id.required' => 'Indica la unidad de cada ingrediente',
        ]);
    }

    public function crearReceta(Request $request)
    {
        return $this->guardarReceta($request, null);
    }

    private function guardarReceta(Request $request, ?Receta $receta)
    {
        $validator = $this->validarReceta($request);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $anterior = $receta?->id;
        $receta = ProduccionService::guardarReceta($validator->validated(), $request->user(), $receta);
        $nueva = $anterior && $receta->id !== $anterior;

        return response()->json([
            'success' => 1,
            'message' => $nueva ? "Receta guardada como versión {$receta->version} (la anterior ya tenía producciones)" : 'Receta guardada',
            'receta'  => $this->formatoReceta($receta->fresh()),
        ], $anterior ? 200 : 201);
    }

    public function actualizarReceta(Request $request, Receta $receta)
    {
        return $this->guardarReceta($request, $receta);
    }

    // POST /produccion/recetas/{receta}/activar {activa}
    public function activarReceta(Request $request, Receta $receta)
    {
        $receta = ProduccionService::activarReceta($receta, $request->boolean('activa', true));

        return response()->json([
            'success' => 1,
            'message' => $receta->activa ? "Versión {$receta->version} activada" : 'Receta desactivada',
            'receta'  => $this->formatoReceta($receta->fresh()),
        ]);
    }

    // ── Producción ────────────────────────────────────────────

    // Los ingredientes de una receta solo los ve quien gestiona recetas o tiene «produccion.ver_receta»;
    // sin él se puede producir igual (se descuenta lo de la receta), pero no se ve con qué
    private function veIngredientes(): bool
    {
        $usuario = request()->user();

        return $usuario && ($usuario->can('recetas.gestionar') || $usuario->can('produccion.ver_receta'));
    }

    private function formatoProduccion(Produccion $p, bool $completo = true): array
    {
        $p->loadMissing(['producto:id,nombre,unidad_medida_id', 'producto.unidadMedida:id,abreviatura', 'receta:id,version', 'usuario:id,nombre']);
        $planificada = (float) $p->cantidad_planificada;

        $datos = [
            'id'                   => $p->id,
            'numero'               => $p->numero,
            'fecha'                => $p->fecha->toDateString(),
            'receta_id'            => $p->receta_id,
            'version'              => $p->receta?->version,
            'producto_id'          => $p->producto_id,
            'producto'             => $p->producto?->nombre,
            'unidad'               => $p->producto?->unidadMedida?->abreviatura,
            'cantidad_planificada' => $planificada,
            'cantidad_producida'   => (float) $p->cantidad_producida,
            'rendimiento'          => $planificada > 0 ? round((float) $p->cantidad_producida / $planificada * 100, 1) : null,
            'costo_total'          => round((float) $p->costo_total, 2),
            'costo_unitario'       => (float) $p->costo_unitario,
            'estado'               => $p->estado,
            'observaciones'        => $p->observaciones,
            'usuario'              => $p->usuario?->nombre,
            'turno'                => $p->turno?->etiqueta(),
            'motivo_anulacion'     => $p->motivo_anulacion,
            'creada'               => $p->created_at->format('Y-m-d H:i'),
        ];

        if ($completo && !$this->veIngredientes()) {
            $datos['ingredientes_ocultos'] = true;
            $datos['consumos'] = [];
        } elseif ($completo) {
            $datos['ingredientes_ocultos'] = false;
            $p->loadMissing(['consumos.producto:id,nombre,unidad_medida_id', 'consumos.producto.unidadMedida:id,abreviatura']);
            $datos['consumos'] = $p->consumos->map(fn ($c) => [
                'producto_id'      => $c->producto_id,
                'producto'         => $c->producto?->nombre,
                'unidad'           => $c->producto?->unidadMedida?->abreviatura,
                'cantidad_teorica' => (float) $c->cantidad_teorica,
                'cantidad_real'    => (float) $c->cantidad_real,
                'diferencia'       => round((float) $c->cantidad_real - (float) $c->cantidad_teorica, 4),
                'costo_unitario'   => (float) $c->costo_unitario,
                'costo_total'      => round((float) $c->costo_total, 2),
            ]);
        }

        return $datos;
    }

    // GET /produccion?desde=&hasta=&producto_id=&estado=
    public function index(Request $request)
    {
        $hasta = $request->filled('hasta') ? $request->hasta : today()->toDateString();
        $desde = $request->filled('desde') ? $request->desde : $hasta;

        $producciones = Produccion::whereBetween('fecha', [$desde, $hasta])
            ->when($request->filled('producto_id'), fn ($q) => $q->where('producto_id', $request->producto_id))
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->estado))
            ->orderByDesc('fecha')->orderByDesc('id')->limit(300)->get();

        return response()->json(['success' => 1, 'producciones' => $producciones->map(fn ($p) => $this->formatoProduccion($p, false))]);
    }

    public function show(Produccion $produccion)
    {
        return response()->json(['success' => 1, 'produccion' => $this->formatoProduccion($produccion)]);
    }

    // POST /produccion
    public function store(Request $request)
    {
        $validator = validator($request->all(), [
            'uuid'                   => 'required|uuid',
            'receta_id'              => 'required|integer',
            'fecha'                  => 'nullable|date',
            'cantidad_planificada'   => 'nullable|numeric|gt:0|max:999999',
            'cantidad_producida'     => 'required|numeric|gt:0|max:999999',
            'observaciones'          => 'nullable|string|max:500',
            'consumos'               => 'nullable|array',
            'consumos.*.producto_id' => 'required|integer|distinct',
            'consumos.*.cantidad_real' => 'required|numeric|min:0',
        ], [
            'receta_id.required'          => 'Elige la receta',
            'cantidad_producida.required' => 'Indica cuánto se produjo',
            'cantidad_producida.gt'       => 'La cantidad producida debe ser mayor a 0',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $produccion = ProduccionService::producir($validator->validated(), $request->user());

        return response()->json([
            'success'    => 1,
            'message'    => "Producción {$produccion->numero} registrada",
            'produccion' => $this->formatoProduccion($produccion->fresh()),
        ], $produccion->wasRecentlyCreated ? 201 : 200);
    }

    public function anular(Request $request, Produccion $produccion)
    {
        $validator = validator($request->all(), ['motivo' => 'required|string|max:300'], ['motivo.required' => 'Indica el motivo de la anulación']);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $produccion = ProduccionService::anularProduccion($produccion, $request->motivo, $request->user());

        return response()->json(['success' => 1, 'message' => "Producción {$produccion->numero} anulada", 'produccion' => $this->formatoProduccion($produccion->fresh())]);
    }

    // ── Mermas ────────────────────────────────────────────────

    private function formatoMerma(Merma $m): array
    {
        $m->loadMissing(['producto:id,nombre,unidad_medida_id', 'producto.unidadMedida:id,abreviatura', 'usuario:id,nombre']);

        return [
            'id'               => $m->id,
            'fecha'            => $m->fecha->toDateString(),
            'producto_id'      => $m->producto_id,
            'producto'         => $m->producto?->nombre,
            'unidad'           => $m->producto?->unidadMedida?->abreviatura,
            'cantidad'         => (float) $m->cantidad,
            'motivo'           => $m->motivo,
            'motivo_texto'     => Merma::MOTIVOS[$m->motivo],
            'detalle'          => $m->detalle,
            'costo_unitario'   => (float) $m->costo_unitario,
            'costo_total'      => round((float) $m->costo_total, 2),
            'anulada'          => $m->anulada,
            'motivo_anulacion' => $m->motivo_anulacion,
            'usuario'          => $m->usuario?->nombre,
            'turno'            => $m->turno?->etiqueta(),
            'registrada'       => $m->created_at->format('Y-m-d H:i'),
        ];
    }

    // GET /mermas?desde=&hasta=&motivo=
    public function mermas(Request $request)
    {
        $hasta = $request->filled('hasta') ? $request->hasta : today()->toDateString();
        $desde = $request->filled('desde') ? $request->desde : $hasta;

        $mermas = Merma::whereBetween('fecha', [$desde, $hasta])
            ->when($request->filled('motivo'), fn ($q) => $q->where('motivo', $request->motivo))
            ->orderByDesc('fecha')->orderByDesc('id')->limit(500)->get();

        return response()->json([
            'success' => 1,
            'mermas'  => $mermas->map(fn ($m) => $this->formatoMerma($m)),
            'total'   => round((float) $mermas->where('anulada', false)->sum('costo_total'), 2),
        ]);
    }

    // POST /mermas {fecha, items: [{uuid, producto_id, cantidad, motivo, detalle}]}
    public function registrarMermas(Request $request)
    {
        $validator = validator($request->all(), [
            'fecha'               => 'nullable|date',
            'items'               => 'required|array|min:1|max:200',
            'items.*.uuid'        => 'required|uuid|distinct',
            'items.*.producto_id' => 'required|integer',
            'items.*.cantidad'    => 'required|numeric|min:0|max:99999',
            'items.*.motivo'      => 'required|in:' . implode(',', array_keys(Merma::MOTIVOS)),
            'items.*.detalle'     => 'nullable|string|max:300',
        ], [
            'items.required'          => 'Indica al menos un producto',
            'items.*.motivo.required' => 'Indica el motivo de cada merma',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $mermas = ProduccionService::registrarMermas($request->input('fecha', today()->toDateString()), $request->items, $request->user());
        $costo = round(collect($mermas)->sum(fn ($m) => (float) $m->costo_total), 2);

        return response()->json([
            'success' => 1,
            'message' => count($mermas) . ' merma(s) registrada(s) · costo $' . number_format($costo, 2),
            'mermas'  => collect($mermas)->map(fn ($m) => $this->formatoMerma($m)),
        ], 201);
    }

    public function anularMerma(Request $request, Merma $merma)
    {
        $validator = validator($request->all(), ['motivo' => 'required|string|max:300'], ['motivo.required' => 'Indica el motivo de la anulación']);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $merma = ProduccionService::anularMerma($merma, $request->motivo, $request->user());

        return response()->json(['success' => 1, 'message' => 'Merma anulada', 'merma' => $this->formatoMerma($merma->fresh())]);
    }

    // GET /produccion/resumen?desde=&hasta=
    public function resumen(Request $request)
    {
        $validator = validator($request->all(), ['desde' => 'nullable|date', 'hasta' => 'nullable|date|after_or_equal:desde']);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $hasta = $request->filled('hasta') ? Carbon::parse($request->hasta) : today();
        $desde = $request->filled('desde') ? Carbon::parse($request->desde) : $hasta->copy()->subDays(6);

        $resumen = ProduccionService::resumen($desde, $hasta);
        $resumen['ingredientes_ocultos'] = !$this->veIngredientes();
        if ($resumen['ingredientes_ocultos']) {
            $resumen['consumos'] = [];
        }

        return response()->json(['success' => 1, 'resumen' => $resumen]);
    }
}
