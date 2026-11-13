<?php

namespace App\Http\Controllers\Api\Gastos;

use App\Http\Controllers\Controller;
use App\Models\CategoriaGasto;
use App\Models\Gasto;
use App\Services\AuditoriaService;
use App\Services\GastoService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class GastoApiController extends Controller
{
    private function errorValidacion($validator)
    {
        return response()->json([
            'success' => 0,
            'message' => $validator->errors()->first(),
            'errors'  => $validator->errors(),
        ], 422);
    }

    private function formato(Gasto $g): array
    {
        $g->loadMissing(['categoria:id,nombre', 'proveedor:id,nombre', 'metodoPago:id,nombre', 'usuario:id,nombre', 'anuladoPor:id,nombre']);

        return [
            'id'               => $g->id,
            'numero'           => $g->numero,
            'fecha'            => $g->fecha->toDateString(),
            'categoria_id'     => $g->categoria_id,
            'categoria'        => $g->categoria?->nombre,
            'proveedor_id'     => $g->proveedor_id,
            'proveedor'        => $g->proveedor?->nombre,
            'descripcion'      => $g->descripcion,
            'monto'            => (float) $g->monto,
            'origen'           => $g->origen,
            'turno_id'         => $g->turno_id,
            'metodo'           => $g->metodoPago?->nombre,
            'referencia'       => $g->referencia,
            'numero_documento' => $g->numero_documento,
            'tiene_comprobante' => (bool) $g->comprobante,
            'comprobante_tipo' => $g->comprobante ? strtolower(pathinfo($g->comprobante, PATHINFO_EXTENSION)) : null,
            'usuario'          => $g->usuario?->nombre,
            'turno'            => $g->turno?->etiqueta(),
            'registrado'       => $g->created_at->format('Y-m-d H:i'),
            'anulado'          => $g->anulado,
            'anulado_por'      => $g->anuladoPor?->nombre,
            'motivo_anulacion' => $g->motivo_anulacion,
        ];
    }

    // ── Categorías ────────────────────────────────────────────

    // GET /gastos/categorias?todas=1
    public function categorias(Request $request)
    {
        $categorias = CategoriaGasto::withCount('gastos')
            ->when(!$request->boolean('todas'), fn ($q) => $q->where('activo', true))
            ->orderBy('nombre')->get();

        return response()->json([
            'success'    => 1,
            'categorias' => $categorias->map(fn ($c) => [
                'id' => $c->id, 'nombre' => $c->nombre, 'descripcion' => $c->descripcion, 'activo' => $c->activo, 'gastos' => $c->gastos_count,
            ]),
        ]);
    }

    public function guardarCategoria(Request $request, ?int $id = null)
    {
        $categoria = $id ? CategoriaGasto::findOrFail($id) : null;

        $validator = validator($request->all(), [
            'nombre'      => ['required', 'string', 'max:100', Rule::unique('categorias_gasto', 'nombre')->ignore($categoria?->id)],
            'descripcion' => 'nullable|string|max:300',
            'activo'      => 'nullable|boolean',
        ], [
            'nombre.required' => 'El nombre de la categoría es obligatorio',
            'nombre.unique'   => 'Ya existe una categoría con ese nombre',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $antes = $categoria?->only(['nombre', 'descripcion', 'activo']);
        $categoria
            ? $categoria->update($validator->validated())
            : $categoria = CategoriaGasto::create([...$validator->validated(), 'activo' => $request->boolean('activo', true)]);

        AuditoriaService::registrar('gastos', $antes ? 'editar_categoria' : 'crear_categoria', $categoria, $antes, $categoria->only(['nombre', 'descripcion', 'activo']));

        return response()->json(['success' => 1, 'message' => 'Categoría guardada', 'categoria' => $categoria], $antes ? 200 : 201);
    }

    public function actualizarCategoria(Request $request, int $id)
    {
        return $this->guardarCategoria($request, $id);
    }

    // ── Gastos ────────────────────────────────────────────────

    // GET /gastos?desde=&hasta=&categoria_id=&origen=&buscar=&estado=
    public function index(Request $request)
    {
        $validator = validator($request->all(), [
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date|after_or_equal:desde',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $hasta = $request->filled('hasta') ? $request->hasta : today()->toDateString();
        $desde = $request->filled('desde') ? $request->desde : today()->startOfMonth()->toDateString();

        $consulta = Gasto::whereBetween('fecha', [$desde, $hasta])
            ->when($request->filled('categoria_id'), fn ($q) => $q->where('categoria_id', $request->categoria_id))
            ->when($request->filled('origen'), fn ($q) => $q->where('origen', $request->origen))
            ->when($request->input('estado') === 'anulados', fn ($q) => $q->where('anulado', true))
            ->when($request->input('estado') === 'vigentes', fn ($q) => $q->where('anulado', false))
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($w) => $w
                ->where('descripcion', 'like', "%{$request->buscar}%")
                ->orWhere('numero', 'like', "%{$request->buscar}%")
                ->orWhere('numero_documento', 'like', "%{$request->buscar}%")));

        $total = (clone $consulta)->where('anulado', false)->sum('monto');
        $gastos = $consulta->orderByDesc('fecha')->orderByDesc('id')->paginate(min($request->integer('per_page', 25), 100));

        return response()->json([
            'success'       => 1,
            'gastos'        => collect($gastos->items())->map(fn ($g) => $this->formato($g)),
            'total_monto'   => round((float) $total, 2),
            'pagina'        => $gastos->currentPage(),
            'total_paginas' => $gastos->lastPage(),
            'total'         => $gastos->total(),
        ]);
    }

    // POST /gastos (multipart si trae comprobante)
    public function store(Request $request)
    {
        $validator = validator($request->all(), [
            'uuid'             => 'required|uuid',
            'fecha'            => 'nullable|date',
            'categoria_id'     => 'required|integer',
            'proveedor_id'     => 'nullable|integer',
            'descripcion'      => 'required|string|max:300',
            'monto'            => 'required|numeric|gt:0|max:9999999',
            'origen'           => 'required|in:caja,externo',
            'caja_id'          => 'required_if:origen,caja|nullable|integer|exists:cajas,id',
            'metodo_pago_id'   => 'nullable|integer|exists:metodos_pago,id',
            'referencia'       => 'nullable|string|max:100',
            'numero_documento' => 'nullable|string|max:50',
            'comprobante'      => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
        ], [
            'categoria_id.required' => 'Elige la categoría del gasto',
            'descripcion.required'  => 'Describe el gasto',
            'monto.required'        => 'Indica el monto',
            'monto.gt'              => 'El monto debe ser mayor a 0',
            'origen.required'       => 'Indica si se paga desde la caja o fuera de caja',
            'caja_id.required_if'   => 'Indica la caja de la que sale el gasto',
            'comprobante.mimes'     => 'El comprobante debe ser una imagen (JPG, PNG, WEBP) o un PDF',
            'comprobante.max'       => 'El comprobante no puede pesar más de 5 MB',
        ]);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $gasto = GastoService::registrar($validator->safe()->except('comprobante'), $request->user(), $request->file('comprobante'));

        return response()->json([
            'success' => 1,
            'message' => "Gasto {$gasto->numero} de $" . number_format((float) $gasto->monto, 2) . ' registrado',
            'gasto'   => $this->formato($gasto->fresh()),
        ], $gasto->wasRecentlyCreated ? 201 : 200);
    }

    // GET /gastos/{gasto}/comprobante
    public function comprobante(Gasto $gasto)
    {
        abort_if(!$gasto->comprobante || !Storage::disk(GastoService::DISCO)->exists($gasto->comprobante), 404, 'El gasto no tiene comprobante');

        return Storage::disk(GastoService::DISCO)->response($gasto->comprobante, "comprobante-{$gasto->numero}." . pathinfo($gasto->comprobante, PATHINFO_EXTENSION));
    }

    // POST /gastos/{gasto}/anular
    public function anular(Request $request, Gasto $gasto)
    {
        $validator = validator($request->all(), [
            'motivo'           => 'required|string|max:300',
        ], ['motivo.required' => 'Indica el motivo de la anulación']);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $gasto = GastoService::anular($gasto, $request->motivo, $request->user());

        return response()->json(['success' => 1, 'message' => "Gasto {$gasto->numero} anulado", 'gasto' => $this->formato($gasto->fresh())]);
    }

    // GET /gastos/resumen?desde=&hasta=
    public function resumen(Request $request)
    {
        $validator = validator($request->all(), ['desde' => 'nullable|date', 'hasta' => 'nullable|date|after_or_equal:desde']);
        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $hasta = $request->filled('hasta') ? Carbon::parse($request->hasta) : today();
        $desde = $request->filled('desde') ? Carbon::parse($request->desde) : $hasta->copy()->startOfMonth();

        return response()->json(['success' => 1, 'resumen' => GastoService::resumen($desde, $hasta)]);
    }
}
