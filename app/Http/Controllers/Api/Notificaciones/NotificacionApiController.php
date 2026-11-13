<?php

namespace App\Http\Controllers\Api\Notificaciones;

use App\Http\Controllers\Controller;
use App\Models\Notificacion;
use App\Services\InventarioService;
use Illuminate\Http\Request;

/**
 * Notificaciones del usuario que inició sesión. Cada quien ve, marca y borra solo las suyas.
 */
class NotificacionApiController extends Controller
{
    // GET /notificaciones/resumen — para la campana: sin leer y las más recientes
    public function resumen(Request $request)
    {
        $base = Notificacion::where('usuario_id', $request->user()->id);

        return response()->json([
            'success'   => 1,
            'no_leidas' => (clone $base)->noLeidas()->count(),
            'recientes' => $this->formatear((clone $base)->with('producto.inventario')->latest('created_at')->latest('id')->limit(8)->get()),
        ]);
    }

    // GET /notificaciones?estado=no_leidas|leidas&page=
    public function index(Request $request)
    {
        $request->validate(['estado' => 'nullable|in:no_leidas,leidas']);

        $usuarioId = $request->user()->id;
        $pagina = Notificacion::with('producto.inventario')
            ->where('usuario_id', $usuarioId)
            ->when($request->estado === 'no_leidas', fn ($q) => $q->whereNull('leida_at'))
            ->when($request->estado === 'leidas', fn ($q) => $q->whereNotNull('leida_at'))
            ->latest('created_at')->latest('id')
            ->paginate(min($request->integer('per_page', 30), 100));

        return response()->json([
            'success'        => 1,
            'notificaciones' => $this->formatear(collect($pagina->items())),
            'pagina'         => $pagina->currentPage(),
            'total_paginas'  => $pagina->lastPage(),
            'total'          => $pagina->total(),
            'no_leidas'      => Notificacion::where('usuario_id', $usuarioId)->noLeidas()->count(),
        ]);
    }

    // POST /notificaciones/leer-todas
    public function leerTodas(Request $request)
    {
        $marcadas = Notificacion::where('usuario_id', $request->user()->id)
            ->noLeidas()
            ->update(['leida_at' => now()]);

        return response()->json(['success' => 1, 'message' => 'Todas marcadas como vistas', 'marcadas' => $marcadas]);
    }

    // PATCH /notificaciones/{id}/leer   (leida=false la vuelve a marcar sin leer)
    public function leer(Request $request, int $id)
    {
        $notificacion = $this->propia($request, $id);
        $notificacion->leida_at = $request->boolean('leida', true) ? now() : null;
        $notificacion->save();

        return response()->json(['success' => 1]);
    }

    // DELETE /notificaciones/{id}
    public function destroy(Request $request, int $id)
    {
        $this->propia($request, $id)->delete();

        return response()->json(['success' => 1, 'message' => 'Notificación borrada']);
    }

    // DELETE /notificaciones?solo=leidas — borra las vistas, o todas si no se indica
    public function vaciar(Request $request)
    {
        $request->validate(['solo' => 'nullable|in:leidas']);

        $borradas = Notificacion::where('usuario_id', $request->user()->id)
            ->when($request->solo === 'leidas', fn ($q) => $q->whereNotNull('leida_at'))
            ->delete();

        return response()->json(['success' => 1, 'message' => "{$borradas} notificación(es) borrada(s)", 'borradas' => $borradas]);
    }

    private function propia(Request $request, int $id): Notificacion
    {
        return Notificacion::where('usuario_id', $request->user()->id)->findOrFail($id);
    }

    private function formatear($notificaciones)
    {
        return $notificaciones->map(function (Notificacion $n) {
            $inventario = $n->producto?->inventario;

            return [
                'id'          => $n->id,
                'tipo'        => $n->tipo,
                'titulo'      => $n->titulo,
                'mensaje'     => $n->mensaje,
                'enlace'      => $n->enlace,
                'producto_id' => $n->producto_id,
                'leida'       => $n->leida_at !== null,
                'fecha'       => $n->created_at->toIso8601String(),
                // Cómo está hoy: el aviso puede ser viejo y el producto ya repuesto
                'sigue_en_alerta' => $inventario
                    ? InventarioService::enAlerta((float) $inventario->stock_actual, (float) $inventario->stock_minimo)
                    : null,
            ];
        })->values();
    }
}
