<?php

namespace App\Http\Controllers\Api\Mesas;

use App\Http\Controllers\Api\Pos\VentaApiController;
use App\Http\Controllers\Controller;
use App\Models\Comanda;
use Illuminate\Support\Collection;
use App\Services\VentaService;
use App\Models\Inventario;
use App\Models\ConfiguracionNegocio;
use App\Models\GrupoModificador;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\OrdenTicket;
use App\Models\Producto;
use App\Models\Receta;
use App\Models\Terminal;
use App\Services\ImpresionService;
use App\Services\OrdenService;
use App\Services\ProduccionService;
use Illuminate\Http\Request;

class MesaApiController extends Controller
{
    private function errorValidacion($validator)
    {
        return response()->json([
            'success' => 0,
            'message' => $validator->errors()->first(),
            'errors'  => $validator->errors(),
        ], 422);
    }

    private function validar(Request $request, array $reglas, array $mensajes = [])
    {
        $validator = validator($request->all(), $reglas, $mensajes);
        return $validator->fails() ? [null, $this->errorValidacion($validator)] : [$validator->validated(), null];
    }

    public static function formatoItem(OrdenItem $i): array
    {
        return [
            'id'              => $i->id,
            'ticket_id'       => $i->ticket_id,
            'producto_id'     => $i->producto_id,
            'nombre'          => $i->nombre_producto,
            'descripcion'     => $i->descripcion(),
            'cantidad'        => (float) $i->cantidad,
            'precio_unitario' => (float) $i->precio_unitario,
            'subtotal'        => $i->subtotal(),
            'modificadores'   => $i->modificadores ?? [],
            'ajustes'         => $i->ajustes ?? [],
            'texto_ajustes'   => $i->textoAjustes(),
            'notas'           => $i->notas,
            'motivo_cancelacion' => $i->motivo_cancelacion,
            'estado'          => $i->estado,
            'area'            => $i->area?->nombre,
            'comanda'         => $i->comanda?->numero,
            'pagado'          => (bool) $i->venta_id,
            'venta_id'        => $i->venta_id,
            'usuario'         => $i->usuario?->nombre,
            'hora'            => $i->created_at->format('H:i'),
        ];
    }

    public static function formatoComanda(Comanda $c): array
    {
        $c->loadMissing(['area', 'orden.terminal', 'usuario:id,nombre', 'items']);

        return [
            'id'           => $c->id,
            'numero'       => $c->numero,
            'orden_id'     => $c->orden_id,
            'terminal'     => $c->orden->terminal->nombre,
            'terminal_tipo' => $c->orden->terminal->tipo,
            // La cuenta ("Ticket 1", "Juan") cuando toda la comanda es de una sola, como en el ticket térmico
            'cuenta'       => ($cuentas = $c->items->pluck('ticket_id')->unique())->count() === 1
                ? \App\Models\OrdenTicket::find($cuentas->first())?->nombre : null,
            'area'         => $c->area?->nombre,
            'color'        => $c->area?->color ?? '#6b7280',
            'estado'       => $c->estado,
            'notas'        => $c->notas,
            'usuario'      => $c->usuario?->nombre,
            'enviada_at'   => $c->enviada_at->format('Y-m-d H:i:s'),
            'iniciada_at'  => $c->iniciada_at?->format('Y-m-d H:i:s'),
            'terminada_at' => $c->terminada_at?->format('Y-m-d H:i:s'),
            'entregada_at' => $c->entregada_at?->format('Y-m-d H:i:s'),
            'items'        => $c->items->map(fn (OrdenItem $i) => [
                'id'            => $i->id,
                'nombre'        => $i->nombre_producto,
                'cantidad'      => (float) $i->cantidad,
                'modificadores' => \App\Models\Modificador::textos($i->modificadores),
                'notas'         => $i->notas,
                'ajustes'       => $i->textoAjustes(),
                'cancelado'     => $i->estado === 'cancelado',
            ]),
        ];
    }

    public static function formatoOrden(Orden $o): array
    {
        $o->load(['terminal', 'cliente:id,nombre', 'usuario:id,nombre', 'tickets',
            'items' => fn ($q) => $q->with(['area:id,nombre', 'comanda:id,numero', 'usuario:id,nombre'])->orderBy('id'),
            'comandas' => fn ($q) => $q->orderByDesc('id')]);

        $vigentes = $o->items->where('estado', '!=', 'cancelado');

        return [
            'id'          => $o->id,
            'uuid'        => $o->uuid,
            'numero'      => $o->numero,
            'estado'      => $o->estado,
            'terminal'    => ['id' => $o->terminal->id, 'nombre' => $o->terminal->nombre, 'tipo' => $o->terminal->tipo],
            'comensales'  => $o->comensales,
            'cliente'     => $o->cliente ? ['id' => $o->cliente->id, 'nombre' => $o->cliente->nombre] : null,
            'usuario'     => $o->usuario?->nombre,
            'notas'       => $o->notas,
            'abierta_at'  => $o->abierta_at->format('Y-m-d H:i:s'),
            'tickets'     => $o->tickets->map(function (OrdenTicket $t) use ($o) {
                $items = $o->items->where('ticket_id', $t->id);
                $vigentes = $items->where('estado', '!=', 'cancelado');
                return [
                    'id'         => $t->id,
                    'nombre'     => $t->nombre,
                    'estado'     => $t->estado,
                    'posicion'   => $t->posicion,
                    'total'      => round($vigentes->sum(fn ($i) => $i->subtotal()), 2),
                    'por_cobrar' => round($vigentes->whereNull('venta_id')->sum(fn ($i) => $i->subtotal()), 2),
                    'items'      => $items->map(fn ($i) => self::formatoItem($i))->values(),
                ];
            })->values(),
            'comandas'    => $o->comandas->map(fn ($c) => self::formatoComanda($c))->values(),
            'totales'     => [
                'total'      => round($vigentes->sum(fn ($i) => $i->subtotal()), 2),
                'cobrado'    => round($vigentes->whereNotNull('venta_id')->sum(fn ($i) => $i->subtotal()), 2),
                'por_cobrar' => round($vigentes->whereNull('venta_id')->sum(fn ($i) => $i->subtotal()), 2),
                'por_enviar' => $vigentes->where('estado', 'pendiente')->count(),
                'listos'     => $vigentes->where('estado', 'listo')->count(),
            ],
        ];
    }

    // ── Mapa y catálogo ───────────────────────────────────────

    // GET /mesas/mapa
    public function mapa()
    {
        $terminales = Terminal::with(['ordenAbierta.tickets', 'ordenAbierta.usuario:id,nombre',
            'ordenAbierta.items' => fn ($q) => $q->where('estado', '!=', 'cancelado')])
            ->where('activo', true)->orderBy('orden')->orderBy('nombre')->get();

        return response()->json([
            'success'    => 1,
            'terminales' => $terminales->map(function (Terminal $t) {
                $o = $t->ordenAbierta;
                return [
                    'id'        => $t->id,
                    'nombre'    => $t->nombre,
                    'tipo'      => $t->tipo,
                    'zona'      => $t->zona,
                    'capacidad' => $t->capacidad,
                    'caja_id'   => $t->caja_id,
                    'orden'     => $o ? [
                        'id'         => $o->id,
                        'numero'     => $o->numero,
                        'abierta_at' => $o->abierta_at->format('Y-m-d H:i:s'),
                        'comensales' => $o->comensales,
                        'usuario'    => $o->usuario?->nombre,
                        'tickets'    => $o->tickets->where('estado', 'abierto')->pluck('nombre')->values(),
                        'total'      => round($o->items->sum(fn ($i) => $i->subtotal()), 2),
                        'por_cobrar' => round($o->items->whereNull('venta_id')->sum(fn ($i) => $i->subtotal()), 2),
                        'por_enviar' => $o->items->where('estado', 'pendiente')->count(),
                        'listos'     => $o->items->where('estado', 'listo')->count(),
                    ] : null,
                ];
            }),
        ]);
    }

    // GET /mesas/catalogo: productos a la venta con sus grupos de modificadores
    public function catalogo()
    {
        $productos = Producto::with(['categorias:id,nombre', 'gruposModificadores:id', 'inventario:producto_id,stock_actual,stock_minimo', 'unidadMedida:id,abreviatura'])
            ->where('activo', true)->where('se_vende', true)->orderBy('orden_pos')->orderBy('nombre')->get();

        // Lo que lleva cada plato con receta de venta: el mesero puede quitar o aumentar sin cambiar el precio
        $recetas = Receta::with(['ingredientes.producto.unidadMedida', 'ingredientes.unidad'])
            ->whereIn('producto_id', $productos->where('controla_stock', false)->pluck('id'))
            ->where('activa', true)->where('uso', 'venta')->get()->keyBy('producto_id');

        $grupos = GrupoModificador::with(['opciones' => fn ($q) => $q->where('activo', true)])->where('activo', true)->get();
        // Las tarjetas muestran la existencia real (lo de cuentas abiertas aún no sale del
        // inventario): al agregar más de lo que hay se avisa, y el cobro es el que lo exige
        $agotadas = self::opcionesAgotadas($grupos);
        $stockInsumos = ProduccionService::stockDeInsumos($recetas);

        return response()->json([
            'success'    => 1,
            'permitir_stock_negativo' => (bool) \App\Models\ConfiguracionNegocio::actual()->permitir_stock_negativo,
            'productos'  => $productos->map(fn (Producto $p) => [
                'id'               => $p->id,
                'nombre'           => $p->nombre,
                'sku'              => $p->sku,
                'codigo_barras'    => $p->codigo_barras,
                'precio'           => (float) $p->precio_venta,
                'imagen'           => $p->imagen,
                'color'            => $p->color_pos,
                'permite_fraccion' => $p->permite_fraccion,
                'unidad'           => $p->unidadMedida?->abreviatura,
                'categorias'       => $p->categorias->pluck('id'),
                'grupos'           => $p->gruposModificadores->pluck('id'),
                'stock'            => $p->controla_stock ? (float) ($p->inventario?->stock_actual ?? 0) : null,
                'permite_sin_existencia' => $p->controla_stock && $p->permite_sin_existencia,
                'disponible'       => $p->controla_stock ? round((float) ($p->inventario?->stock_actual ?? 0), 4) : null,
                'bajo_minimo'      => $p->controla_stock && \App\Services\InventarioService::enAlerta(
                    (float) ($p->inventario?->stock_actual ?? 0), (float) ($p->inventario?->stock_minimo ?? 0)),
                'receta'           => $recetas->has($p->id) ? ProduccionService::ingredientesPorUnidad($recetas->get($p->id)) : [],
                // Platos con receta: cuántos alcanzan con los insumos que hay
                'alcanza'          => $recetas->has($p->id) ? ProduccionService::alcanza($recetas->get($p->id), $stockInsumos, []) : null,
            ]),
            'categorias' => $productos->flatMap->categorias->unique('id')->sortBy('nombre')->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre])->values(),
            'grupos'     => $grupos->map(fn (GrupoModificador $g) => [
                'id'          => $g->id,
                'nombre'      => $g->nombre,
                'obligatorio' => $g->obligatorio,
                'multiple'    => $g->multiple,
                // Unidades en total del grupo (contando repeticiones); maximo null = sin tope
                'minimo'      => (int) $g->minimo,
                'maximo'      => $g->maximo,
                'opciones'    => $g->opciones->map(fn ($o) => ['id' => $o->id, 'nombre' => $o->nombre, 'precio' => (float) $o->precio,
                    'maximo' => max(1, (int) $o->cantidad_maxima), 'obligatorio' => $o->obligatorio, 'exclusiva' => $o->exclusiva,
                    // Sin stock negativo: la opción cuyo insumo ya no alcanza para una porción
                    'agotado' => $agotadas->contains($o->id)]),
            ]),
        ]);
    }

    /**
     * Opciones de extras cuyo insumo no alcanza para una porción (Leche entera sin
     * existencia), con la existencia real. Con stock negativo, ninguna.
     */
    public static function opcionesAgotadas($grupos): Collection
    {
        if (ConfiguracionNegocio::actual()->permitir_stock_negativo) {
            return collect();
        }
        $opciones = $grupos->flatMap->opciones->filter(fn ($o) => $o->producto_id && (float) $o->cantidad > 0);
        if ($opciones->isEmpty()) {
            return collect();
        }
        $stock = Inventario::whereIn('producto_id', $opciones->pluck('producto_id'))->pluck('stock_actual', 'producto_id');
        // Insumos con «Permitir vender sin existencia» (la Coca-Cola de Soda): la opción se elige aunque marque 0
        $sinLimite = Producto::whereIn('id', $opciones->pluck('producto_id'))->where('permite_sin_existencia', true)->pluck('id')->flip();

        return $opciones->filter(function ($o) use ($stock, $sinLimite) {
            if ($sinLimite->has($o->producto_id)) {
                return false;
            }
            $porcion = VentaService::consumoDeModificadores([$o->id], 1)[0]['cantidad'] ?? 0;
            return $porcion > 0 && (float) ($stock[$o->producto_id] ?? 0) < $porcion - 0.0001;
        })->pluck('id')->values();
    }

    // ── Orden de una terminal ─────────────────────────────────

    // GET /mesas/terminales/{terminal}: la orden abierta (o null si está libre)
    public function terminal(Terminal $terminal)
    {
        $orden = Orden::where('terminal_id', $terminal->id)->where('estado', 'abierta')->first();

        return response()->json([
            'success'  => 1,
            'terminal' => ['id' => $terminal->id, 'nombre' => $terminal->nombre, 'tipo' => $terminal->tipo, 'zona' => $terminal->zona, 'capacidad' => $terminal->capacidad],
            'orden'    => $orden ? self::formatoOrden($orden) : null,
        ]);
    }

    // POST /mesas/terminales/{terminal}/abrir {comensales?, cliente_id?, notas?}
    public function abrir(Request $request, Terminal $terminal)
    {
        [$datos, $error] = $this->validar($request, [
            'uuid'       => 'nullable|uuid',
            'comensales' => 'nullable|integer|min:1|max:99',
            'cliente_id' => 'nullable|integer|exists:clientes,id',
            'notas'      => 'nullable|string|max:300',
        ]);
        if ($error) return $error;

        $orden = OrdenService::abrir($terminal, $request->user(), $datos);

        return response()->json(['success' => 1, 'message' => "{$terminal->nombre} abierta", 'orden' => self::formatoOrden($orden)]);
    }

    // PUT /mesas/ordenes/{orden} {comensales?, cliente_id?, notas?}
    public function actualizar(Request $request, Orden $orden)
    {
        [$datos, $error] = $this->validar($request, [
            'comensales' => 'nullable|integer|min:1|max:99',
            'cliente_id' => 'nullable|integer|exists:clientes,id',
            'notas'      => 'nullable|string|max:300',
        ]);
        if ($error) return $error;

        abort_if($orden->estado !== 'abierta', 422, 'La orden ya está cerrada');
        $orden->update($datos);

        return response()->json(['success' => 1, 'orden' => self::formatoOrden($orden)]);
    }

    // POST /mesas/ordenes/{orden}/items {ticket_id?, items: [{producto_id, cantidad, modificadores, notas}]}
    public function agregar(Request $request, Orden $orden)
    {
        [$datos, $error] = $this->validar($request, [
            'ticket_id'               => 'nullable|integer',
            'items'                   => 'required|array|min:1|max:50',
            'items.*.producto_id'     => 'required|integer',
            'items.*.cantidad'        => 'required|numeric|gt:0|max:9999',
            'items.*.modificadores'   => 'nullable|array|max:60',
            'items.*.modificadores.*' => 'integer',
            'items.*.notas'           => 'nullable|string|max:200',
            'items.*.ajustes'               => 'nullable|array|max:30',
            'items.*.ajustes.*.producto_id' => 'required|integer',
            'items.*.ajustes.*.cantidad'    => 'required|numeric|min:0',
        ], ['items.required' => 'Agrega al menos un producto']);
        if ($error) return $error;

        OrdenService::agregarItems($orden, $datos['ticket_id'] ?? null, $datos['items'], $request->user());

        // Avisos de existencia: se agregó igual, el cobro es el que exige que haya
        return response()->json(['success' => 1, 'avisos' => OrdenService::$avisos, 'orden' => self::formatoOrden($orden->fresh())]);
    }

    // PUT /mesas/items/{item} {cantidad?, notas?, ajustes?}
    public function actualizarItem(Request $request, OrdenItem $item)
    {
        [$datos, $error] = $this->validar($request, [
            'cantidad' => 'nullable|numeric|gt:0|max:9999',
            'notas'    => 'nullable|string|max:200',
            'ajustes'  => 'nullable|array|max:30',
            'ajustes.*.producto_id' => 'required|integer',
            'ajustes.*.cantidad'    => 'required|numeric|min:0',
            'modificadores'   => 'nullable|array|max:60',
            'modificadores.*' => 'integer',
        ]);
        if ($error) return $error;

        OrdenService::actualizarItem($item, $request->only(array_intersect(['cantidad', 'notas', 'ajustes', 'modificadores'], array_keys($request->all()))));

        return response()->json(['success' => 1, 'avisos' => OrdenService::$avisos, 'orden' => self::formatoOrden($item->orden->fresh())]);
    }

    // POST /mesas/items/{item}/quitar {motivo?, cantidad?, autorizacion?}: todo o parte (1 de 3)
    public function quitarItem(Request $request, OrdenItem $item)
    {
        [$datos, $error] = $this->validar($request, [
            'motivo'           => 'nullable|string|max:300',
            'cantidad'         => 'nullable|numeric|gt:0|max:9999',
        ]);
        if ($error) return $error;

        $cancelado = OrdenService::quitarItem($item, $request->user(), $datos['motivo'] ?? null,
            isset($datos['cantidad']) ? (float) $datos['cantidad'] : null);

        return response()->json([
            'success'   => 1,
            'message'   => $cancelado ? 'Producto cancelado' : 'Producto quitado',
            'impresion' => $cancelado ? self::avisoCancelacion($cancelado, $datos['motivo'], $request->user()) : null,
            'orden'     => self::formatoOrden($item->orden->fresh()),
        ]);
    }

    // POST /mesas/comandas/{comanda}/anular {motivo, autorizacion?}: cancela toda la comanda
    public function anularComanda(Request $request, Comanda $comanda)
    {
        [$datos, $error] = $this->validar($request, ['motivo' => 'required|string|max:300'],
            ['motivo.required' => 'Indica por qué se anula la comanda']);
        if ($error) return $error;

        $cancelado = OrdenService::anularComanda($comanda, $request->user(), $datos['motivo']);

        return response()->json([
            'success'   => 1,
            'message'   => "Comanda #{$comanda->numero} anulada",
            'impresion' => self::avisoCancelacion($cancelado, $datos['motivo'], $request->user()),
            'orden'     => self::formatoOrden($comanda->orden->fresh()),
        ]);
    }

    // El ticket CANCELADO sale en el área; si falla, la cancelación igual queda
    private static function avisoCancelacion(array $cancelado, string $motivo, $usuario): array
    {
        $trabajos = collect(ImpresionService::imprimirCancelacion($cancelado['items'], $motivo, $usuario, $cancelado['autorizador']));

        return [
            'impresas' => $trabajos->where('estado', 'impreso')->count(),
            'errores'  => $trabajos->where('estado', 'error')->pluck('error')->values(),
        ];
    }

    // POST /mesas/ordenes/{orden}/enviar {notas?}: comandas a cocina y barra
    public function enviar(Request $request, Orden $orden)
    {
        // item_ids: enviar solo esos productos (lo demás sigue sin enviar)
        [$datos, $error] = $this->validar($request, ['notas' => 'nullable|string|max:300', 'item_ids' => 'nullable|array|min:1', 'item_ids.*' => 'integer']);
        if ($error) return $error;

        $comandas = OrdenService::enviar($orden, $request->user(), $datos['notas'] ?? null, $datos['item_ids'] ?? null);

        // Imprimir nunca frena el envío: si falla, la comanda ya está en la pantalla de cocina
        $trabajos = $comandas->flatMap(fn ($c) => ImpresionService::imprimirComanda($c, $request->user()->id));
        $errores = $trabajos->where('estado', 'error');

        return response()->json([
            'success'   => 1,
            'message'   => $comandas->isEmpty()
                ? 'Productos entregados (no pasan por cocina)'
                : 'Comanda #' . $comandas->first()->numero . ' enviada a ' . $comandas->map(fn ($c) => $c->area?->nombre)->filter()->join(' y '),
            'impresion' => [
                'impresas' => $trabajos->where('estado', 'impreso')->count(),
                'errores'  => $errores->pluck('error')->values(),
                'sin_impresora' => $comandas->isNotEmpty() && $trabajos->isEmpty(),
            ],
            // Para imprimir desde el navegador (un ticket por área) si la térmica no está o falla
            'comandas'  => $comandas->map(fn ($c) => self::formatoComanda($c->fresh()))->values(),
            'orden'     => self::formatoOrden($orden->fresh()),
        ]);
    }

    // POST /mesas/ordenes/{orden}/entregar {item_ids}
    public function entregar(Request $request, Orden $orden)
    {
        [$datos, $error] = $this->validar($request, ['item_ids' => 'required|array|min:1', 'item_ids.*' => 'integer']);
        if ($error) return $error;

        $marcados = OrdenService::entregarItems($orden, $datos['item_ids']);

        return response()->json(['success' => 1, 'message' => "{$marcados} producto(s) marcado(s) como entregado(s)",
            'orden' => self::formatoOrden($orden->fresh())]);
    }

    // ── Cuentas separadas ─────────────────────────────────────

    // POST /mesas/ordenes/{orden}/tickets {nombre?}
    public function crearTicket(Request $request, Orden $orden)
    {
        [$datos, $error] = $this->validar($request, ['nombre' => 'nullable|string|max:60']);
        if ($error) return $error;

        $ticket = OrdenService::crearTicket($orden, $datos['nombre'] ?? null);

        return response()->json(['success' => 1, 'ticket_id' => $ticket->id, 'orden' => self::formatoOrden($orden->fresh())], 201);
    }

    // PUT /mesas/tickets/{ticket} {nombre}
    public function renombrarTicket(Request $request, OrdenTicket $ticket)
    {
        [$datos, $error] = $this->validar($request, ['nombre' => 'required|string|max:60'], ['nombre.required' => 'Ponle un nombre a la cuenta']);
        if ($error) return $error;

        OrdenService::renombrarTicket($ticket, $datos['nombre']);

        return response()->json(['success' => 1, 'orden' => self::formatoOrden($ticket->orden->fresh())]);
    }

    // DELETE /mesas/tickets/{ticket}
    public function eliminarTicket(OrdenTicket $ticket)
    {
        $orden = $ticket->orden;
        $nombre = $ticket->nombre;
        OrdenService::eliminarTicket($ticket);

        return response()->json(['success' => 1, 'message' => "{$nombre} se borró", 'orden' => self::formatoOrden($orden->fresh())]);
    }

    // POST /mesas/ordenes/{orden}/mover {item_ids, ticket_id}
    public function mover(Request $request, Orden $orden)
    {
        [$datos, $error] = $this->validar($request, [
            'item_ids'   => 'required|array|min:1',
            'item_ids.*' => 'integer',
            'ticket_id'  => 'required|integer',
        ]);
        if ($error) return $error;

        OrdenService::moverItems($orden, $datos['item_ids'], $datos['ticket_id']);

        return response()->json(['success' => 1, 'orden' => self::formatoOrden($orden->fresh())]);
    }

    // POST /mesas/items/{item}/dividir {cantidad, ticket_id}
    public function dividir(Request $request, OrdenItem $item)
    {
        [$datos, $error] = $this->validar($request, ['cantidad' => 'required|numeric|gt:0', 'ticket_id' => 'required|integer']);
        if ($error) return $error;

        OrdenService::dividirItem($item, (float) $datos['cantidad'], $datos['ticket_id']);

        return response()->json(['success' => 1, 'orden' => self::formatoOrden($item->orden->fresh())]);
    }

    // ── Cobro y cierre ────────────────────────────────────────

    // POST /mesas/ordenes/{orden}/precuenta {ticket_id?, caja_id?} — en la térmica (la tablet no imprime desde el navegador)
    public function precuenta(Request $request, Orden $orden)
    {
        [$datos, $error] = $this->validar($request, [
            'ticket_id' => 'nullable|integer',
            'caja_id'   => 'nullable|integer|exists:cajas,id',
        ]);
        if ($error) return $error;

        $trabajo = ImpresionService::imprimirPrecuenta($orden, $datos['ticket_id'] ?? null, $datos['caja_id'] ?? null, $request->user()->id);

        return response()->json([
            'success' => 1,
            'impreso' => $trabajo->estado === 'impreso',
            'message' => $trabajo->estado === 'impreso' ? 'Precuenta impresa' : ($trabajo->error ?? 'No se pudo imprimir'),
        ]);
    }

    // POST /mesas/ordenes/{orden}/cobrar
    public function cobrar(Request $request, Orden $orden)
    {
        [$datos, $error] = $this->validar($request, [
            'uuid'                   => 'required|uuid',
            'caja_id'                => 'required|integer|exists:cajas,id',
            'ticket_id'              => 'nullable|integer',
            'item_ids'               => 'nullable|array',
            'item_ids.*'             => 'integer',
            'cliente_id'             => 'nullable|integer',
            'descuento_global'       => 'nullable|array',
            'descuento_global.tipo'  => 'required_with:descuento_global|in:porcentaje,monto',
            'descuento_global.valor' => 'required_with:descuento_global|numeric|min:0',
            'pagos'                  => 'present|array|max:10',
            'pagos.*.metodo_pago_id' => 'required|integer',
            'pagos.*.monto'          => 'required|numeric|min:0|max:999999',
            'pagos.*.referencia'     => 'nullable|string|max:100',
        ], ['pagos.present' => 'Indica la forma de pago']);
        if ($error) return $error;

        $venta = OrdenService::cobrar($orden, $datos, $request->user());
        $orden = $orden->fresh();

        return response()->json([
            'success' => 1,
            'message' => "Venta {$venta->numero} registrada" . ($orden->estado === 'cerrada' ? " · {$orden->terminal->nombre} queda libre" : ''),
            'venta'   => VentaApiController::formato($venta),
            'orden'   => self::formatoOrden($orden),
        ], $venta->wasRecentlyCreated ? 201 : 200);
    }

    // POST /mesas/ordenes/{orden}/cerrar {motivo?}: liberar la terminal
    public function cerrar(Request $request, Orden $orden)
    {
        [$datos, $error] = $this->validar($request, [
            'motivo'           => 'nullable|string|max:300',
        ]);
        if ($error) return $error;

        $orden = OrdenService::cerrar($orden, $request->user(), $datos['motivo'] ?? null);

        return response()->json(['success' => 1, 'message' => "{$orden->terminal->nombre} liberada"]);
    }

    // POST /mesas/ordenes/{orden}/trasladar {terminal_id}
    public function trasladar(Request $request, Orden $orden)
    {
        [$datos, $error] = $this->validar($request, ['terminal_id' => 'required|integer|exists:terminales,id']);
        if ($error) return $error;

        $orden = OrdenService::trasladar($orden, Terminal::find($datos['terminal_id']), $request->user());

        return response()->json(['success' => 1, 'message' => "Orden pasada a {$orden->terminal->nombre}", 'orden' => self::formatoOrden($orden)]);
    }

    // POST /mesas/comandas/{comanda}/entregar
    public function entregarComanda(Comanda $comanda)
    {
        $comanda = OrdenService::entregarComanda($comanda);

        return response()->json(['success' => 1, 'comanda' => self::formatoComanda($comanda->fresh())]);
    }

    // POST /mesas/comandas/{comanda}/imprimir — reimpresión (sale marcada como COPIA)
    public function imprimirComanda(Request $request, Comanda $comanda)
    {
        $trabajos = collect(ImpresionService::imprimirComanda($comanda, $request->user()->id, true));
        $errores = $trabajos->where('estado', 'error');

        return response()->json([
            'success' => 1,
            'impreso' => $trabajos->isNotEmpty() && $errores->isEmpty(),
            'comanda' => self::formatoComanda($comanda),
            'message' => $trabajos->isEmpty()
                ? 'No hay impresora de comandas para estos productos: imprime desde el navegador'
                : ($errores->isEmpty() ? 'Comanda impresa' : $errores->pluck('error')->implode(' · ')),
        ]);
    }
}
