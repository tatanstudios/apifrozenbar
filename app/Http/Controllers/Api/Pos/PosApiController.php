<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\ConfiguracionNegocio;
use App\Models\GrupoModificador;
use App\Models\Producto;
use App\Models\Receta;
use App\Services\InventarioService;
use App\Services\ProduccionService;

class PosApiController extends Controller
{
    // GET /pos/catalogo — todo lo que el POS necesita para vender (se guarda en el dispositivo)
    public function catalogo()
    {
        $lista = Producto::with(['categorias:id,area_preparacion_id', 'categorias.area:id,nombre,activo', 'inventario', 'unidadMedida', 'gruposModificadores:id'])
            ->where('activo', true)
            ->where('se_vende', true)
            ->orderBy('orden_pos')
            ->orderBy('nombre')
            ->get();

        // Lo que lleva cada plato con receta de venta: se puede quitar o aumentar sin cambiar el precio
        $recetas = Receta::with(['ingredientes.producto.unidadMedida', 'ingredientes.unidad'])
            ->whereIn('producto_id', $lista->where('controla_stock', false)->pluck('id'))
            ->where('activa', true)->where('uso', 'venta')->get()->keyBy('producto_id');

        $grupos = GrupoModificador::with(['opciones' => fn ($q) => $q->where('activo', true)])->where('activo', true)->get();
        $agotadas = \App\Http\Controllers\Api\Mesas\MesaApiController::opcionesAgotadas($grupos);
        // Platos con receta: cuántos alcanzan con los insumos (solo cuenta sin stock negativo)
        $stockInsumos = ProduccionService::stockDeInsumos($recetas);
        $apartadoInsumos = $recetas->isEmpty() ? [] : \App\Services\OrdenService::insumosApartados();

        $productos = $lista->map(fn (Producto $p) => [
                'id'               => $p->id,
                'nombre'           => $p->nombre,
                'sku'              => $p->sku,
                'codigo_barras'    => $p->codigo_barras,
                'imagen'           => $p->imagen,
                'color'            => $p->color_pos,
                'precio'           => (float) $p->precio_venta,
                'categorias'       => $p->categorias->pluck('id'),
                'unidad'           => $p->unidadMedida?->abreviatura,
                'permite_fraccion' => $p->permite_fraccion,
                'controla_stock'   => $p->controla_stock,
                // Se vende aunque no haya existencia, aunque el negocio no permita stock negativo
                'permite_sin_existencia' => $p->controla_stock && $p->permite_sin_existencia,
                'consumo_interno'  => $p->consumo_interno,
                // Área de su comanda (null = comanda general); sirve para imprimir desde el navegador
                'area'             => $p->areaDeComanda()?->nombre,
                'stock'            => $p->controla_stock ? (float) ($p->inventario->stock_actual ?? 0) : null,
                'bajo_minimo'      => $p->controla_stock && InventarioService::enAlerta(
                    (float) ($p->inventario->stock_actual ?? 0),
                    (float) ($p->inventario->stock_minimo ?? 0)
                ),
                // Extras (leche, endulzante) y receta ajustable ("sin huevo")
                'grupos'           => $p->gruposModificadores->pluck('id'),
                'receta'           => $recetas->has($p->id) ? ProduccionService::ingredientesPorUnidad($recetas->get($p->id)) : [],
                'alcanza'          => $recetas->has($p->id) ? ProduccionService::alcanza($recetas->get($p->id), $stockInsumos, $apartadoInsumos) : null,
            ]);

        // Solo categorías que tienen productos a la venta
        $usadas = $productos->pluck('categorias')->flatten()->unique();
        $categorias = Categoria::where('estado', true)->whereIn('id', $usadas)->orderBy('nombre')->get(['id', 'nombre']);

        return response()->json([
            'success'      => 1,
            'productos'    => $productos,
            'categorias'   => $categorias,
            'grupos'       => $grupos->map(fn (GrupoModificador $g) => [
                'id'          => $g->id,
                'nombre'      => $g->nombre,
                'obligatorio' => $g->obligatorio,
                'multiple'    => $g->multiple,
                // Unidades en total del grupo (contando repeticiones); maximo null = sin tope
                'minimo'      => (int) $g->minimo,
                'maximo'      => $g->maximo,
                'opciones'    => $g->opciones->map(fn ($o) => ['id' => $o->id, 'nombre' => $o->nombre, 'precio' => (float) $o->precio,
                    'maximo' => max(1, (int) $o->cantidad_maxima), 'obligatorio' => $o->obligatorio, 'exclusiva' => $o->exclusiva, 'agotado' => $agotadas->contains($o->id)]),
            ]),
            // false: no se agrega a la venta más de lo que hay en existencia
            'permitir_stock_negativo' => (bool) ConfiguracionNegocio::actual()->permitir_stock_negativo,
            'generado_at'  => now()->toIso8601String(),
        ]);
    }
}
