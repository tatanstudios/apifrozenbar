<?php

namespace App\Http\Controllers\Api\Config;

use App\Http\Controllers\Controller;
use App\Models\Administrador;
use App\Models\Auditoria;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AuditoriaApiController extends Controller
{
    // GET /auditoria/filtros: valores disponibles para los filtros
    public function filtros()
    {
        return response()->json([
            'success'  => 1,
            'modulos'  => Auditoria::query()->distinct()->orderBy('modulo')->pluck('modulo'),
            'acciones' => Auditoria::query()->distinct()->orderBy('accion')->pluck('accion'),
            'usuarios' => Administrador::orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    // GET /auditoria?desde=&hasta=&usuario_id=&modulo=&accion=&buscar=&page=
    public function index(Request $request)
    {
        $validator = validator($request->all(), [
            'desde'      => 'nullable|date',
            'hasta'      => 'nullable|date|after_or_equal:desde',
            'usuario_id' => 'nullable|integer',
            'modulo'     => 'nullable|string|max:50',
            'accion'     => 'nullable|string|max:50',
            'buscar'     => 'nullable|string|max:100',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => 0, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $hasta = $request->filled('hasta') ? Carbon::parse($request->hasta)->endOfDay() : now()->endOfDay();
        $desde = $request->filled('desde') ? Carbon::parse($request->desde)->startOfDay() : $hasta->copy()->subDays(6)->startOfDay();

        $registros = Auditoria::with('usuario:id,nombre')
            ->whereBetween('created_at', [$desde, $hasta])
            ->when($request->filled('usuario_id'), fn ($q) => $q->where('usuario_id', $request->usuario_id))
            ->when($request->filled('modulo'), fn ($q) => $q->where('modulo', $request->modulo))
            ->when($request->filled('accion'), fn ($q) => $q->where('accion', $request->accion))
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($w) => $w
                ->where('descripcion', 'like', "%{$request->buscar}%")
                ->orWhere('entidad_id', $request->buscar)))
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 50), 100));

        return response()->json([
            'success'       => 1,
            'registros'     => collect($registros->items())->map(fn (Auditoria $a) => [
                'id'          => $a->id,
                'fecha'       => $a->created_at->format('Y-m-d H:i:s'),
                'usuario'     => $a->usuario?->nombre ?? 'Sistema',
                'modulo'      => $a->modulo,
                'accion'      => $a->accion,
                'entidad'     => $a->entidad_tipo ? class_basename($a->entidad_tipo) . ($a->entidad_id ? " #{$a->entidad_id}" : '') : null,
                'descripcion' => $a->descripcion,
                'antes'       => $a->datos_antes,
                'despues'     => $a->datos_despues,
                'ip'          => $a->ip,
                'dispositivo' => $a->user_agent,
            ]),
            'pagina'        => $registros->currentPage(),
            'total_paginas' => $registros->lastPage(),
            'total'         => $registros->total(),
        ]);
    }
}
