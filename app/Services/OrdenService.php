<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Models\Administrador;
use App\Models\Comanda;
use App\Models\ConfiguracionNegocio;
use App\Models\Inventario;
use App\Models\GrupoModificador;
use App\Models\Modificador;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\OrdenTicket;
use App\Models\Producto;
use App\Models\Receta;
use App\Models\Terminal;
use App\Models\Venta;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Órdenes de mesa: se abren en una terminal, se les agregan productos (con
 * modificadores) en una o varias cuentas, se envían a cocina o barra como
 * comandas y se cobran por cuenta o por productos elegidos. El cobro usa
 * VentaService: inventario, caja y ticket funcionan igual que en mostrador.
 */
class OrdenService
{
    // ── Orden ─────────────────────────────────────────────────

    /**
     * Orden abierta de la terminal; si no hay, la abre con su primera cuenta.
     * $mostrador: pedidos por cobrar de la venta rápida, que viven en la terminal de la caja
     */
    public static function abrir(Terminal $terminal, Administrador $usuario, array $datos = [], bool $mostrador = false): Orden
    {
        if (!$terminal->activo) {
            throw new NegocioException("{$terminal->nombre} está desactivada");
        }
        if ($terminal->tipo === 'caja' && !$mostrador) {
            throw new NegocioException("{$terminal->nombre} es la caja de mostrador: vende desde el punto de venta rápido");
        }

        if ($abierta = Orden::where('terminal_id', $terminal->id)->where('estado', 'abierta')->first()) {
            return $abierta;
        }

        try {
            return DB::transaction(function () use ($terminal, $usuario, $datos) {
                $orden = Orden::create([
                    'uuid'        => $datos['uuid'] ?? (string) Str::uuid(),
                    'numero'      => CorrelativoService::siguiente('orden', 'O'),
                    'terminal_id' => $terminal->id,
                    'estado'      => 'abierta',
                    'comensales'  => $datos['comensales'] ?? null,
                    'cliente_id'  => $datos['cliente_id'] ?? null,
                    'usuario_id'  => $usuario->id,
                    'notas'       => $datos['notas'] ?? null,
                    'abierta_at'  => now(),
                ]);
                OrdenTicket::create(['orden_id' => $orden->id, 'nombre' => 'Ticket 1', 'posicion' => 1]);

                return $orden;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Otro dispositivo la abrió al mismo tiempo: se usa esa
            return Orden::where('terminal_id', $terminal->id)->where('estado', 'abierta')->firstOrFail();
        }
    }

    private static function bloquear(Orden $orden): Orden
    {
        $orden = Orden::whereKey($orden->id)->lockForUpdate()->first();
        if ($orden->estado !== 'abierta') {
            throw new NegocioException('La orden ya está cerrada: vuelve al mapa de mesas');
        }
        return $orden;
    }

    private static function ticketDe(Orden $orden, ?int $ticketId): OrdenTicket
    {
        $ticket = $ticketId
            ? OrdenTicket::where('orden_id', $orden->id)->whereKey($ticketId)->first()
            : OrdenTicket::where('orden_id', $orden->id)->where('estado', 'abierto')->orderBy('posicion')->first();

        if (!$ticket) {
            $ticket = self::nuevoTicket($orden, null);
        }
        if ($ticket->estado !== 'abierto') {
            throw new NegocioException("{$ticket->nombre} ya está pagado: agrega los productos en otra cuenta");
        }
        return $ticket;
    }

    // ── Productos de la orden ─────────────────────────────────

    /**
     * Lo que ya está apartado en cuentas abiertas (sin cobrar ni cancelar), por producto.
     * Se descuenta del inventario al cobrar, pero mientras tanto ya no está disponible.
     */
    public static function apartado(array $productoIds, ?int $excluirItemId = null): Collection
    {
        if (!$productoIds) {
            return collect();
        }

        return OrdenItem::whereIn('producto_id', $productoIds)
            ->whereNull('venta_id')
            ->where('estado', '!=', 'cancelado')
            ->whereHas('orden', fn ($q) => $q->where('estado', 'abierta'))
            ->when($excluirItemId, fn ($q) => $q->whereKeyNot($excluirItemId))
            ->groupBy('producto_id')
            ->selectRaw('producto_id, SUM(cantidad) as total')
            ->pluck('total', 'producto_id')
            ->map(fn ($v) => (float) $v);
    }

    /**
     * Avisos de existencia de la última vez que se agregó o cambió algo (agregarItems,
     * actualizarItem). Los controladores los devuelven en «avisos» para mostrarlos al mesero.
     * @var string[]
     */
    public static array $avisos = [];

    private static function avisar(string $texto): void
    {
        if (!in_array($texto, self::$avisos, true)) {
            self::$avisos[] = $texto;
        }
    }

    private static function cantidadTexto(float $c): string
    {
        return rtrim(rtrim(number_format(max(0, $c), 3, '.', ''), '0'), '.');
    }

    /**
     * Sin stock negativo permitido (Datos del negocio), avisa si lo pedido en cuentas abiertas
     * pasa de lo que hay. No bloquea: el mesero lo agrega igual y el cobro es el que exige
     * que haya existencia (InventarioService no deja quedar en negativo).
     */
    private static function validarExistencia(Producto $producto, float $cantidad, ?int $excluirItemId = null): void
    {
        if (!$producto->controla_stock || $producto->permite_sin_existencia || ConfiguracionNegocio::actual()->permitir_stock_negativo) {
            return;
        }
        $stock = (float) (Inventario::where('producto_id', $producto->id)->value('stock_actual') ?? 0);
        // Las líneas anteriores de la misma petición ya están guardadas: apartado() las cuenta
        $pedido = (self::apartado([$producto->id], $excluirItemId)->get($producto->id) ?? 0) + $cantidad;

        if ($pedido > $stock + 0.0001) {
            self::avisar($stock <= 0.0001
                ? "No hay existencia de {$producto->nombre}: se agregó, pero no se podrá cobrar hasta que haya."
                : "No alcanzan las unidades de {$producto->nombre}: hay " . self::cantidadTexto($stock)
                    . ' y en cuentas abiertas ya van ' . self::cantidadTexto($pedido) . '. Se agregó, pero al cobrar se revisará la existencia.');
        }
    }

    /**
     * Insumos que gasta una línea, por insumo y en la unidad del inventario: los de su
     * receta de venta (con lo que el cliente pidió distinto) y los de sus extras
     * (Leche entera: 200 ml). Es lo que se descuenta al cobrar.
     * @return array<int, float> [insumo_id => cantidad]
     */
    public static function insumosDeLinea(Producto $producto, float $cantidad, array $modificadorIds, array $factores = []): array
    {
        $total = [];
        if (!$producto->controla_stock) {
            $receta = Receta::where('producto_id', $producto->id)->where('activa', true)->where('uso', 'venta')->first();
            foreach ($receta ? ProduccionService::consumoDeVenta($receta, $cantidad) : [] as $s) {
                $total[$s['producto_id']] = ($total[$s['producto_id']] ?? 0) + $s['cantidad'] * ($factores[$s['producto_id']] ?? 1);
            }
        }
        foreach (VentaService::consumoDeModificadores($modificadorIds, $cantidad) as $s) {
            $total[$s['producto_id']] = ($total[$s['producto_id']] ?? 0) + $s['cantidad'];
        }
        return array_filter($total, fn ($c) => $c > 0);
    }

    /**
     * Insumos ya comprometidos en cuentas abiertas sin cobrar (aún no salen del inventario).
     * @return array<int, float>
     */
    public static function insumosApartados(?int $excluirItemId = null): array
    {
        $total = [];
        OrdenItem::with('producto')->whereNull('venta_id')->where('estado', '!=', 'cancelado')
            ->whereHas('orden', fn ($q) => $q->where('estado', 'abierta'))
            ->when($excluirItemId, fn ($q) => $q->whereKeyNot($excluirItemId))
            ->get()
            ->each(function (OrdenItem $i) use (&$total) {
                if (!$i->producto) {
                    return;
                }
                $ids = Modificador::ids($i->modificadores);
                foreach (self::insumosDeLinea($i->producto, (float) $i->cantidad, $ids, $i->factoresAjuste()) as $id => $c) {
                    $total[$id] = ($total[$id] ?? 0) + $c;
                }
            });
        return $total;
    }

    /**
     * Sin stock negativo, avisa si no alcanzan los insumos de la receta y de los extras
     * («No hay existencia de Leche entera») contando lo apartado en cuentas abiertas. No bloquea.
     * Las líneas anteriores de la misma petición ya están guardadas: insumosApartados() las cuenta.
     */
    private static function validarInsumos(Producto $producto, float $cantidad, array $modificadorIds, array $factores = [],
        ?int $excluirItemId = null): void
    {
        if (ConfiguracionNegocio::actual()->permitir_stock_negativo) {
            return;
        }
        $necesita = self::insumosDeLinea($producto, $cantidad, $modificadorIds, $factores);
        if (!$necesita) {
            return;
        }
        $apartado = self::insumosApartados($excluirItemId);
        $insumos = Producto::with('inventario:producto_id,stock_actual', 'unidadMedida')->whereIn('id', array_keys($necesita))->get();
        foreach ($insumos as $insumo) {
            $disponible = (float) ($insumo->inventario?->stock_actual ?? 0) - ($apartado[$insumo->id] ?? 0);
            if ($necesita[$insumo->id] > $disponible + 0.0001) {
                $u = $insumo->unidadMedida?->abreviatura ? ' ' . $insumo->unidadMedida->abreviatura : '';
                self::avisar($disponible <= 0.0001
                    ? "No hay existencia de {$insumo->nombre} para {$producto->nombre}: se agregó, pero al cobrar se revisará."
                    : 'Solo quedan ' . self::cantidadTexto($disponible) . "{$u} de {$insumo->nombre}: no alcanza para {$producto->nombre}. Se agregó, pero al cobrar se revisará.");
            }
        }
    }

    /**
     * Valida los modificadores elegidos contra los grupos del producto y
     * devuelve [modificadores elegidos, precio extra].
     */
    public static function resolverModificadores(Producto $producto, array $ids): array
    {
        $grupos = $producto->gruposModificadores()->where('activo', true)
            ->with(['opciones' => fn ($q) => $q->where('activo', true)])->get();

        $elegidos = [];
        $extra = 0.0;
        $porGrupo = [];

        // Un id repetido es la misma opción pedida varias veces (Huevo extra ×2), hasta su máximo
        $pedidas = array_count_values(array_map('intval', $ids));
        // Las opciones obligatorias van siempre: si no vienen (app anterior), se agregan una vez
        foreach ($grupos->flatMap->opciones->where('obligatorio', true) as $o) {
            $pedidas[$o->id] ??= 1;
        }

        foreach ($pedidas as $id => $veces) {
            $grupo = $grupos->first(fn (GrupoModificador $g) => $g->opciones->contains('id', $id));
            if (!$grupo) {
                throw new NegocioException("Uno de los extras no corresponde a {$producto->nombre}");
            }
            $opcion = $grupo->opciones->firstWhere('id', $id);
            $porGrupo[$grupo->id][] = $opcion;
            $maximo = max(1, (int) $opcion->cantidad_maxima);
            if ($veces > $maximo) {
                throw new NegocioException($maximo === 1
                    ? "«{$opcion->nombre}» se pide una sola vez por {$producto->nombre}"
                    : "«{$opcion->nombre}»: máximo {$maximo} por {$producto->nombre}");
            }
            $elegidos[] = ['id' => $opcion->id, 'grupo' => $grupo->nombre, 'opcion' => $opcion->nombre, 'precio' => (float) $opcion->precio]
                + ($veces > 1 ? ['cantidad' => $veces] : []);
            $extra += (float) $opcion->precio * $veces;
        }

        // Unidades en total por grupo, contando repeticiones («Tipo de leche: al menos 1, máximo 2»)
        foreach ($grupos as $grupo) {
            if ($grupo->opciones->isEmpty()) {
                continue;
            }
            $total = collect($porGrupo[$grupo->id] ?? [])->sum(fn ($o) => $pedidas[$o->id]);
            $minimo = max((int) $grupo->minimo, $grupo->obligatorio ? 1 : 0);
            if ($total < $minimo) {
                throw new NegocioException($minimo === 1
                    ? "Elige «{$grupo->nombre}» para {$producto->nombre}"
                    : "«{$grupo->nombre}»: {$grupo->reglaTexto()} para {$producto->nombre} (llevas {$total})");
            }
            if ($grupo->maximo !== null && $total > $grupo->maximo) {
                throw new NegocioException("«{$grupo->nombre}»: {$grupo->reglaTexto()} para {$producto->nombre} (llevas {$total})");
            }
        }

        // Una opción «una sola» no se combina con otras de su grupo («Sin azúcar» y «Azúcar extra»)
        foreach ($porGrupo as $opciones) {
            $sola = collect($opciones)->firstWhere('exclusiva', true);
            if ($sola && count($opciones) > 1) {
                throw new NegocioException("«{$sola->nombre}» no se combina con otras opciones de su grupo en {$producto->nombre}");
            }
        }

        return [$elegidos, round($extra, 2)];
    }

    /**
     * Valida lo que el cliente pidió distinto de la receta ("solo 1 huevo", "sin
     * queso", "extra frijol"). El precio no cambia; solo se descuenta lo que salió.
     * @param array $ajustes [['producto_id', 'cantidad' (por unidad vendida, en la unidad del inventario)], ...]
     * @return array|null [['producto_id', 'nombre', 'base', 'cantidad', 'unidad'], ...] solo los que cambian
     */
    public static function resolverAjustes(Producto $producto, array $ajustes): ?array
    {
        if (!$ajustes) {
            return null;
        }
        $receta = $producto->controla_stock ? null
            : Receta::where('producto_id', $producto->id)->where('activa', true)->where('uso', 'venta')->first();
        if (!$receta) {
            throw new NegocioException("{$producto->nombre} no tiene receta de venta: no se le pueden quitar ingredientes");
        }
        $ingredientes = collect(ProduccionService::ingredientesPorUnidad($receta))->keyBy('producto_id');

        $resultado = [];
        foreach ($ajustes as $a) {
            $ing = $ingredientes->get((int) ($a['producto_id'] ?? 0));
            if (!$ing) {
                throw new NegocioException("Uno de los ingredientes no está en la receta de {$producto->nombre}");
            }
            $cantidad = round((float) ($a['cantidad'] ?? 0), 4);
            if ($cantidad < 0 || $cantidad > $ing['base'] * 10) {
                throw new NegocioException("La cantidad de {$ing['nombre']} no es válida");
            }
            if (abs($cantidad - $ing['base']) < 0.0001) {
                continue; // lo normal: no es un cambio
            }
            $resultado[$ing['producto_id']] = [...$ing, 'cantidad' => $cantidad];
        }

        return $resultado ? array_values($resultado) : null;
    }

    /**
     * Agrega productos a una cuenta. Quedan "pendientes" hasta enviarlos.
     * @param array $items [['producto_id', 'cantidad', 'modificadores' => [ids], 'notas'], ...]
     */
    public static function agregarItems(Orden $orden, ?int $ticketId, array $items, Administrador $usuario): Collection
    {
        self::$avisos = [];
        $productos = Producto::with(['categorias.area'])->whereIn('id', collect($items)->pluck('producto_id'))->get()->keyBy('id');

        return DB::transaction(function () use ($orden, $ticketId, $items, $usuario, $productos) {
            $orden = self::bloquear($orden);
            $ticket = self::ticketDe($orden, $ticketId);

            return collect($items)->map(function ($i) use ($orden, $ticket, $usuario, $productos) {
                $producto = $productos->get($i['producto_id']);
                if (!$producto || !$producto->activo || !$producto->se_vende) {
                    throw new NegocioException('Uno de los productos no está disponible para la venta');
                }
                $cantidad = round((float) $i['cantidad'], 4);
                if ($cantidad <= 0) {
                    throw new NegocioException("La cantidad de {$producto->nombre} debe ser mayor a 0");
                }
                if (!$producto->permite_fraccion && floor($cantidad) != $cantidad) {
                    throw new NegocioException("{$producto->nombre} solo se vende por unidades enteras");
                }

                self::validarExistencia($producto, $cantidad);

                [$modificadores, $extra] = self::resolverModificadores($producto, $i['modificadores'] ?? []);

                $ajustes = self::resolverAjustes($producto, $i['ajustes'] ?? []);
                self::validarInsumos($producto, $cantidad, Modificador::ids($modificadores),
                    (new OrdenItem(['ajustes' => $ajustes]))->factoresAjuste());
                $notas = trim((string) ($i['notas'] ?? '')) ?: null;
                $precio = round((float) $producto->precio_venta + $extra, 2);

                // El mismo producto, con los mismos extras, ingredientes y nota, que aún no se
                // envía en esta cuenta y que agregó el mismo mesero: se suma a esa línea
                // («2 × Americano») en vez de repetirla. Lo de otro mesero va en su propia línea
                $igual = OrdenItem::where('orden_id', $orden->id)->where('ticket_id', $ticket->id)
                    ->where('producto_id', $producto->id)->where('estado', 'pendiente')
                    ->where('usuario_id', $usuario->id)->lockForUpdate()->get()
                    ->first(fn (OrdenItem $o) => (float) $o->precio_unitario === $precio
                        && ($o->notas ?: null) === $notas
                        && self::mismaLista($o->modificadores, $modificadores)
                        && self::mismaLista($o->ajustes, $ajustes));
                if ($igual) {
                    $igual->update(['cantidad' => round((float) $igual->cantidad + $cantidad, 4)]);
                    return $igual;
                }

                // El área de preparación sale de la categoría del producto (sin área: se entrega sin comanda)
                $area = $producto->areaDeComanda();

                return OrdenItem::create([
                    'orden_id'        => $orden->id,
                    'ticket_id'       => $ticket->id,
                    'producto_id'     => $producto->id,
                    'nombre_producto' => $producto->nombre,
                    'cantidad'        => $cantidad,
                    'precio_base'     => (float) $producto->precio_venta,
                    'modificadores'   => $modificadores ?: null,
                    'ajustes'         => $ajustes,
                    'precio_unitario' => $precio,
                    'notas'           => $notas,
                    'estado'          => 'pendiente',
                    'area_id'         => $area?->id,
                    'usuario_id'      => $usuario->id,
                ]);
            });
        });
    }

    // Dos listas de extras o ajustes iguales sin importar el orden ni las claves
    private static function mismaLista(?array $a, ?array $b): bool
    {
        $normal = fn (?array $l) => collect($l ?? [])->map(fn ($x) => json_encode(collect($x)->sortKeys()->all()))->sort()->values()->all();
        return $normal($a) === $normal($b);
    }

    // Cambia cantidad o notas de un producto que todavía no se envía
    public static function actualizarItem(OrdenItem $item, array $datos): OrdenItem
    {
        self::$avisos = [];
        return DB::transaction(function () use ($item, $datos) {
            self::bloquear($item->orden);
            $item = OrdenItem::whereKey($item->id)->lockForUpdate()->first();
            if ($item->estado !== 'pendiente') {
                throw new NegocioException('El producto ya se envió a preparar: para cambiarlo, cancélalo y agrégalo de nuevo');
            }
            if (isset($datos['cantidad'])) {
                $cantidad = round((float) $datos['cantidad'], 4);
                if ($cantidad <= 0) {
                    throw new NegocioException('La cantidad debe ser mayor a 0');
                }
                if (!$item->producto->permite_fraccion && floor($cantidad) != $cantidad) {
                    throw new NegocioException("{$item->nombre_producto} solo se vende por unidades enteras");
                }
                if ($cantidad > (float) $item->cantidad) {
                    self::validarExistencia($item->producto, $cantidad, $item->id);
                }
                $item->cantidad = $cantidad;
            }
            if (array_key_exists('notas', $datos)) {
                $item->notas = $datos['notas'];
            }
            if (array_key_exists('ajustes', $datos)) {
                $item->ajustes = self::resolverAjustes($item->producto, $datos['ajustes'] ?? []);
            }
            // Cambiar los extras (era deslactosada, no entera): el precio se recalcula
            if (array_key_exists('modificadores', $datos)) {
                [$modificadores, $extra] = self::resolverModificadores($item->producto, $datos['modificadores'] ?? []);
                $item->modificadores = $modificadores ?: null;
                $item->precio_unitario = round((float) $item->precio_base + $extra, 2);
            }
            if (isset($datos['cantidad']) || array_key_exists('ajustes', $datos) || array_key_exists('modificadores', $datos)) {
                self::validarInsumos($item->producto, (float) $item->cantidad, Modificador::ids($item->modificadores),
                    $item->factoresAjuste(), $item->id);
            }
            $item->save();

            return $item;
        });
    }

    /**
     * Quita un producto, todo o solo parte ($cantidad: 1 de 3 cafés). Si no se ha
     * enviado, se borra o se baja la cantidad. Si ya se envió a preparar, lo
     * cancelado queda como línea "cancelada" con motivo y autorización (PIN del
     * administrador) y se avisa al área con un ticket CANCELADO.
     * @return array|null ['items' => OrdenItem[] cancelados, 'autorizador' => Administrador] si hay que avisar a cocina
     */
    public static function quitarItem(OrdenItem $item, Administrador $usuario, ?string $motivo, ?float $cantidad = null): ?array
    {
        if ($item->venta_id) {
            throw new NegocioException('El producto ya se cobró: anula la venta o registra una devolución');
        }
        if ($item->estado === 'cancelado') {
            throw new NegocioException('El producto ya está cancelado');
        }
        $total = (float) $item->cantidad;
        $cantidad = $cantidad === null ? $total : round($cantidad, 4);
        if ($cantidad <= 0 || $cantidad > $total + 0.00001) {
            throw new NegocioException('La cantidad a cancelar debe ser mayor a 0 y no más de ' . rtrim(rtrim(number_format($total, 3, '.', ''), '0'), '.'));
        }
        if (!$item->producto->permite_fraccion && floor($cantidad) != $cantidad) {
            throw new NegocioException("{$item->nombre_producto} se cancela por unidades enteras");
        }
        $parcial = $cantidad < $total - 0.00001;

        if ($item->estado === 'pendiente') {
            DB::transaction(function () use ($item, $parcial, $total, $cantidad) {
                self::bloquear($item->orden);
                $parcial
                    ? OrdenItem::whereKey($item->id)->where('estado', 'pendiente')->update(['cantidad' => round($total - $cantidad, 4)])
                    : OrdenItem::whereKey($item->id)->where('estado', 'pendiente')->delete();
            });
            return null;
        }

        if (!$motivo) {
            throw new NegocioException('Indica por qué se cancela un producto que ya se envió a preparar');
        }
        $autorizador = AutorizacionService::autorizar($usuario, 'pos.anular', 'Cancelar un producto ya enviado necesita autorización del administrador');

        $cancelado = DB::transaction(function () use ($item, $usuario, $motivo, $autorizador, $parcial, $total, $cantidad) {
            self::bloquear($item->orden);
            $item = OrdenItem::whereKey($item->id)->lockForUpdate()->first();
            if ($item->venta_id || $item->estado === 'cancelado') {
                throw new NegocioException('El producto ya se cobró o se canceló');
            }

            $datos = ['estado' => 'cancelado', 'cancelado_por' => $usuario->id, 'motivo_cancelacion' => $motivo];
            if ($parcial) {
                // La parte que sigue en la mesa se queda; la cancelada va en su propia línea
                $item->update(['cantidad' => round($total - $cantidad, 4)]);
                $cancelado = $item->replicate();
                $cancelado->fill([...$datos, 'cantidad' => $cantidad])->save();
            } else {
                $item->update($datos);
                $cancelado = $item;
            }

            self::cerrarComandaVacia($cancelado->comanda_id);
            self::auditarCancelacion($cancelado, $autorizador, $motivo);

            return $cancelado;
        });

        return ['items' => [$cancelado], 'autorizador' => $autorizador];
    }

    /**
     * Anula una comanda completa (el cliente se fue, se mandó a la mesa equivocada):
     * cancela lo que no se ha cobrado y avisa al área.
     * @return array ['items' => OrdenItem[] cancelados, 'autorizador' => Administrador]
     */
    public static function anularComanda(Comanda $comanda, Administrador $usuario, string $motivo): array
    {
        if ($comanda->estado === 'cancelada') {
            throw new NegocioException('La comanda ya está anulada');
        }
        $autorizador = AutorizacionService::autorizar($usuario, 'pos.anular', 'Anular una comanda necesita autorización del administrador');

        $items = DB::transaction(function () use ($comanda, $usuario, $motivo, $autorizador) {
            self::bloquear($comanda->orden);
            $items = OrdenItem::where('comanda_id', $comanda->id)->where('estado', '!=', 'cancelado')
                ->whereNull('venta_id')->lockForUpdate()->get();
            if ($items->isEmpty()) {
                throw new NegocioException('No queda nada por anular en esta comanda: lo que tenía ya se cobró o se canceló');
            }

            OrdenItem::whereIn('id', $items->pluck('id'))
                ->update(['estado' => 'cancelado', 'cancelado_por' => $usuario->id, 'motivo_cancelacion' => $motivo]);
            self::cerrarComandaVacia($comanda->id);

            $items = OrdenItem::whereIn('id', $items->pluck('id'))->get();
            foreach ($items as $item) {
                self::auditarCancelacion($item, $autorizador, $motivo);
            }

            return $items;
        });

        return ['items' => $items->all(), 'autorizador' => $autorizador];
    }

    // Si la comanda se quedó sin nada que preparar, también se cancela
    private static function cerrarComandaVacia(?int $comandaId): void
    {
        if ($comandaId && !OrdenItem::where('comanda_id', $comandaId)->where('estado', '!=', 'cancelado')->exists()) {
            Comanda::whereKey($comandaId)->update(['estado' => 'cancelada']);
        }
    }

    private static function auditarCancelacion(OrdenItem $item, Administrador $autorizador, string $motivo): void
    {
        AuditoriaService::registrar('mesas', 'cancelar_item', $item, ['estado' => 'enviado'], [
            'producto'       => $item->descripcion(),
            'cantidad'       => (float) $item->cantidad,
            'autorizado_por' => $autorizador->nombre,
        ], $motivo);
    }

    // ── Envío a cocina y barra ────────────────────────────────

    /**
     * Envía lo pendiente: una comanda por área con el mismo número. Lo que no tiene
     * área (pan de vitrina, bebidas embotelladas) no pasa por cocina: queda entregado
     * sin comanda.
     * @return Collection<Comanda>
     */
    public static function enviar(Orden $orden, Administrador $usuario, ?string $notas = null, ?array $itemIds = null): Collection
    {
        return DB::transaction(function () use ($orden, $usuario, $notas, $itemIds) {
            $orden = self::bloquear($orden);
            // Con $itemIds solo se envían esos (la coca ya, el café después); sin ellos, todo lo pendiente
            $pendientes = OrdenItem::where('orden_id', $orden->id)->where('estado', 'pendiente')
                ->when($itemIds !== null, fn ($q) => $q->whereIn('id', $itemIds))->lockForUpdate()->get();

            if ($pendientes->isEmpty()) {
                throw new NegocioException($itemIds !== null ? 'Ninguno de los seleccionados está sin enviar' : 'No hay productos nuevos para enviar');
            }

            $numero = null;
            $comandas = collect();
            foreach ($pendientes->groupBy('area_id') as $areaId => $items) {
                if (!$areaId) {
                    OrdenItem::whereIn('id', $items->pluck('id'))->update(['estado' => 'entregado']);
                    continue;
                }
                $numero ??= CorrelativoService::siguiente('comanda');
                $comanda = Comanda::create([
                    'numero'     => $numero,
                    'orden_id'   => $orden->id,
                    'area_id'    => (int) $areaId,
                    'estado'     => 'pendiente',
                    'notas'      => $notas,
                    'usuario_id' => $usuario->id,
                    'enviada_at' => now(),
                ]);
                OrdenItem::whereIn('id', $items->pluck('id'))->update(['estado' => 'enviado', 'comanda_id' => $comanda->id]);
                $comandas->push($comanda);
            }

            return $comandas;
        });
    }

    /**
     * Marca entregada una comanda desde la mesa. La cocina y la barra trabajan con
     * su ticket impreso, así que no hay pasos intermedios (preparando, listo).
     */
    public static function entregarComanda(Comanda $comanda): Comanda
    {
        return DB::transaction(function () use ($comanda) {
            $comanda = Comanda::whereKey($comanda->id)->lockForUpdate()->first();
            if (in_array($comanda->estado, ['entregada', 'cancelada'], true)) {
                throw new NegocioException('La comanda ya está entregada o cancelada');
            }

            $comanda->update(['estado' => 'entregada', 'entregada_at' => now(), 'iniciada_at' => $comanda->iniciada_at ?? now()]);
            OrdenItem::where('comanda_id', $comanda->id)->where('estado', '!=', 'cancelado')->update(['estado' => 'entregado']);

            return $comanda;
        });
    }

    // Marca entregados productos sueltos desde la mesa (ya están listos o no pasan por cocina)
    // Devuelve cuántos se marcaron; lo que no se ha enviado no se puede entregar
    public static function entregarItems(Orden $orden, array $itemIds): int
    {
        return DB::transaction(function () use ($orden, $itemIds) {
            $orden = self::bloquear($orden);
            $marcados = OrdenItem::where('orden_id', $orden->id)->whereIn('id', $itemIds)
                ->whereIn('estado', ['enviado', 'en_preparacion', 'listo'])->update(['estado' => 'entregado']);
            if ($marcados === 0) {
                $sinEnviar = OrdenItem::where('orden_id', $orden->id)->whereIn('id', $itemIds)->where('estado', 'pendiente')->exists();
                throw new NegocioException($sinEnviar
                    ? 'Esos productos aún no se envían: toca «Enviar» primero y luego márcalos entregados'
                    : 'Esos productos ya estaban entregados');
            }

            // Comandas cuyos productos ya se entregaron todos
            $comandas = OrdenItem::where('orden_id', $orden->id)->whereIn('id', $itemIds)->whereNotNull('comanda_id')->pluck('comanda_id')->unique();
            foreach ($comandas as $id) {
                $quedan = OrdenItem::where('comanda_id', $id)->whereNotIn('estado', ['entregado', 'cancelado'])->exists();
                if (!$quedan) {
                    Comanda::whereKey($id)->whereNotIn('estado', ['entregada', 'cancelada'])->update(['estado' => 'entregada', 'entregada_at' => now()]);
                }
            }

            return $marcados;
        });
    }

    // ── Cuentas separadas ─────────────────────────────────────

    public static function nuevoTicket(Orden $orden, ?string $nombre): OrdenTicket
    {
        $posicion = (int) OrdenTicket::where('orden_id', $orden->id)->max('posicion') + 1;

        return OrdenTicket::create([
            'orden_id' => $orden->id,
            'nombre'   => $nombre ?: "Ticket {$posicion}",
            'posicion' => $posicion,
        ]);
    }

    public static function crearTicket(Orden $orden, ?string $nombre): OrdenTicket
    {
        return DB::transaction(fn () => self::nuevoTicket(self::bloquear($orden), $nombre));
    }

    public static function renombrarTicket(OrdenTicket $ticket, string $nombre): OrdenTicket
    {
        $ticket->update(['nombre' => $nombre]);
        return $ticket;
    }

    // Pasa productos (sin cobrar) a otra cuenta de la misma orden
    public static function moverItems(Orden $orden, array $itemIds, int $ticketId): void
    {
        DB::transaction(function () use ($orden, $itemIds, $ticketId) {
            $orden = self::bloquear($orden);
            $destino = self::ticketDe($orden, $ticketId);

            $movibles = OrdenItem::where('orden_id', $orden->id)->whereIn('id', $itemIds)
                ->whereNull('venta_id')->where('estado', '!=', 'cancelado')->pluck('id');
            if ($movibles->count() !== count(array_unique($itemIds))) {
                throw new NegocioException('Solo se mueven productos de esta orden que no se han cobrado');
            }
            OrdenItem::whereIn('id', $movibles)->update(['ticket_id' => $destino->id]);
        });
    }

    // Separa una línea: pasa parte de la cantidad a otra cuenta (2 frappés → 1 y 1)
    public static function dividirItem(OrdenItem $item, float $cantidad, int $ticketId): OrdenItem
    {
        return DB::transaction(function () use ($item, $cantidad, $ticketId) {
            $orden = self::bloquear($item->orden);
            $item = OrdenItem::whereKey($item->id)->lockForUpdate()->first();
            $destino = self::ticketDe($orden, $ticketId);

            if ($item->venta_id || $item->estado === 'cancelado') {
                throw new NegocioException('Ese producto ya se cobró o se canceló');
            }
            if ($cantidad <= 0 || $cantidad >= (float) $item->cantidad) {
                throw new NegocioException('La cantidad a separar debe ser menor a la de la línea');
            }

            $item->update(['cantidad' => round((float) $item->cantidad - $cantidad, 4)]);
            $nuevo = $item->replicate();
            $nuevo->cantidad = $cantidad;
            $nuevo->ticket_id = $destino->id;
            $nuevo->save();

            return $nuevo;
        });
    }

    /**
     * Borra una cuenta abierta por error. Lo que tenga sin enviar se descarta (no salió
     * comanda ni inventario). Se rechaza si ya está pagada, si tiene algo cobrado o enviado
     * a cocina (eso se cancela con motivo o se mueve), o si es la única cuenta abierta.
     */
    public static function eliminarTicket(OrdenTicket $ticket): void
    {
        DB::transaction(function () use ($ticket) {
            self::bloquear($ticket->orden);
            $ticket = OrdenTicket::whereKey($ticket->id)->lockForUpdate()->first();
            if ($ticket->estado !== 'abierto') {
                throw new NegocioException("{$ticket->nombre} ya está pagada: no se puede borrar");
            }
            $items = OrdenItem::where('ticket_id', $ticket->id)->where('estado', '!=', 'cancelado')->get();
            if ($items->contains(fn ($i) => $i->venta_id)) {
                throw new NegocioException("{$ticket->nombre} ya tiene productos cobrados: no se puede borrar");
            }
            if ($items->contains(fn ($i) => $i->estado !== 'pendiente')) {
                throw new NegocioException("{$ticket->nombre} tiene productos ya enviados a cocina: cancélalos (con motivo) o muévelos a otra cuenta antes de borrarla");
            }
            if (OrdenTicket::where('orden_id', $ticket->orden_id)->where('estado', 'abierto')->count() <= 1) {
                throw new NegocioException("{$ticket->nombre} es la única cuenta abierta: si la mesa ya no consume, usa «Liberar mesa»");
            }
            // Solo quedan productos sin enviar y cancelados sin cobrar
            OrdenItem::where('ticket_id', $ticket->id)->whereNull('venta_id')->delete();
            $ticket->delete();
        });
    }

    // ── Cobro ─────────────────────────────────────────────────

    /**
     * Cobra una cuenta completa o los productos elegidos. La venta se
     * registra con VentaService (stock, caja, cliente, crédito, descuentos)
     * y los productos quedan ligados a ella. Si ya no queda nada por cobrar,
     * la orden se cierra y la mesa queda libre.
     *
     * $datos: uuid, caja_id, ticket_id?, item_ids?, pagos, cliente_id?, descuento_global?, observaciones?
     */
    public static function cobrar(Orden $orden, array $datos, Administrador $usuario): Venta
    {
        if ($existente = Venta::where('uuid', $datos['uuid'])->first()) {
            return $existente;
        }

        return DB::transaction(function () use ($orden, $datos, $usuario) {
            $orden = self::bloquear($orden);

            $items = OrdenItem::where('orden_id', $orden->id)->whereNull('venta_id')->where('estado', '!=', 'cancelado')
                ->when(!empty($datos['item_ids']), fn ($q) => $q->whereIn('id', $datos['item_ids']))
                ->when(empty($datos['item_ids']) && !empty($datos['ticket_id']), fn ($q) => $q->where('ticket_id', $datos['ticket_id']))
                ->lockForUpdate()->get();

            if ($items->isEmpty()) {
                throw new NegocioException('No hay productos por cobrar en esa cuenta');
            }
            if (!empty($datos['item_ids']) && $items->count() !== count(array_unique($datos['item_ids']))) {
                throw new NegocioException('Alguno de los productos ya se cobró o se canceló');
            }

            $venta = VentaService::registrar([
                'uuid'             => $datos['uuid'],
                'caja_id'          => $datos['caja_id'],
                'orden_id'         => $orden->id,
                'cliente_id'       => $datos['cliente_id'] ?? $orden->cliente_id,
                'items'            => $items->map(fn (OrdenItem $i) => [
                    'producto_id'  => $i->producto_id,
                    'cantidad'     => (float) $i->cantidad,
                    'precio_orden' => (float) $i->precio_unitario,
                    'nombre_orden' => $i->descripcion(),
                    // Para el ticket: el producto en su renglón y cada extra debajo («* Leche»)
                    'extras_orden' => collect(Modificador::textos($i->modificadores))->merge($i->textoAjustes())->filter()->values()->all(),
                    'modificadores_orden' => Modificador::ids($i->modificadores),
                    'ajustes_orden'       => $i->factoresAjuste(),
                ])->all(),
                'descuento_global' => $datos['descuento_global'] ?? null,
                'pagos'            => $datos['pagos'],
                'observaciones'    => $datos['observaciones'] ?? "Orden {$orden->numero} · {$orden->terminal->nombre}",
            ], $usuario);

            OrdenItem::whereIn('id', $items->pluck('id'))->update(['venta_id' => $venta->id]);

            // Cuentas sin nada pendiente quedan pagadas
            foreach (OrdenTicket::where('orden_id', $orden->id)->where('estado', 'abierto')->get() as $ticket) {
                $pendiente = OrdenItem::where('ticket_id', $ticket->id)->whereNull('venta_id')->where('estado', '!=', 'cancelado')->exists();
                $tieneItems = OrdenItem::where('ticket_id', $ticket->id)->where('estado', '!=', 'cancelado')->exists();
                if (!$pendiente && $tieneItems) {
                    $ticket->update(['estado' => 'pagado']);
                }
            }

            $quedaPorCobrar = OrdenItem::where('orden_id', $orden->id)->whereNull('venta_id')->where('estado', '!=', 'cancelado')->exists();
            if (!$quedaPorCobrar) {
                $orden->update(['estado' => 'cerrada', 'cerrada_at' => now()]);
            }

            return $venta;
        });
    }

    /**
     * Libera la mesa sin cobrar ("limpiar terminal"). Solo si no queda nada
     * por cobrar; lo pendiente se cancela con motivo y autorización.
     */
    public static function cerrar(Orden $orden, Administrador $usuario, ?string $motivo): Orden
    {
        $porCobrar = OrdenItem::where('orden_id', $orden->id)->whereNull('venta_id')->where('estado', '!=', 'cancelado')->get();

        $autorizador = null;
        if ($porCobrar->isNotEmpty()) {
            if (!$motivo) {
                throw new NegocioException('La mesa tiene productos sin cobrar: indica por qué se cancelan');
            }
            $autorizador = AutorizacionService::autorizar($usuario, 'pos.anular', 'Cancelar productos sin cobrar necesita autorización del administrador');
        }

        return DB::transaction(function () use ($orden, $usuario, $motivo, $porCobrar, $autorizador) {
            $orden = self::bloquear($orden);

            if ($porCobrar->isNotEmpty()) {
                OrdenItem::whereIn('id', $porCobrar->pluck('id'))->update([
                    'estado' => 'cancelado', 'cancelado_por' => $usuario->id, 'motivo_cancelacion' => $motivo,
                ]);
                Comanda::where('orden_id', $orden->id)->whereIn('estado', ['pendiente', 'en_preparacion'])
                    ->whereDoesntHave('items', fn ($q) => $q->where('estado', '!=', 'cancelado'))
                    ->update(['estado' => 'cancelada']);
            }

            $vendida = OrdenItem::where('orden_id', $orden->id)->whereNotNull('venta_id')->exists();
            $orden->update([
                'estado'             => $vendida ? 'cerrada' : 'cancelada',
                'cerrada_at'         => now(),
                'cancelada_por'      => $porCobrar->isNotEmpty() ? $usuario->id : null,
                'motivo_cancelacion' => $porCobrar->isNotEmpty() ? $motivo : null,
            ]);

            if ($porCobrar->isNotEmpty()) {
                AuditoriaService::registrar('mesas', 'cerrar_con_pendientes', $orden, null, [
                    'terminal'       => $orden->terminal->nombre,
                    'productos'      => $porCobrar->map(fn ($i) => (float) $i->cantidad . ' ' . $i->descripcion())->all(),
                    'monto'          => round($porCobrar->sum(fn ($i) => $i->subtotal()), 2),
                    'autorizado_por' => $autorizador?->nombre,
                ], $motivo);
            }

            return $orden;
        });
    }

    // Pasa la orden completa a otra mesa libre
    public static function trasladar(Orden $orden, Terminal $destino, Administrador $usuario): Orden
    {
        if (!$destino->activo || $destino->tipo === 'caja') {
            throw new NegocioException('No se puede trasladar a esa terminal');
        }

        try {
            return DB::transaction(function () use ($orden, $destino, $usuario) {
                $orden = self::bloquear($orden);
                if (Orden::where('terminal_id', $destino->id)->where('estado', 'abierta')->exists()) {
                    throw new NegocioException("{$destino->nombre} está ocupada");
                }
                $origen = $orden->terminal->nombre;
                $orden->update(['terminal_id' => $destino->id]);
                AuditoriaService::registrar('mesas', 'trasladar', $orden, ['terminal' => $origen], ['terminal' => $destino->nombre], "Orden {$orden->numero}", $usuario->id);

                return $orden;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new NegocioException("{$destino->nombre} está ocupada");
        }
    }

    /**
     * Al anular una venta que salió de una orden, sus productos vuelven a
     * quedar por cobrar. Si la orden se había cerrado y la mesa sigue libre,
     * se reabre.
     */
    public static function alAnularVenta(Venta $venta): void
    {
        if (!$venta->orden_id) {
            return;
        }

        $items = OrdenItem::where('venta_id', $venta->id)->get();
        OrdenItem::whereIn('id', $items->pluck('id'))->update(['venta_id' => null]);
        OrdenTicket::whereIn('id', $items->pluck('ticket_id')->unique())->update(['estado' => 'abierto']);

        $orden = Orden::whereKey($venta->orden_id)->lockForUpdate()->first();
        if ($orden->estado === 'cerrada' && !Orden::where('terminal_id', $orden->terminal_id)->where('estado', 'abierta')->exists()) {
            $orden->update(['estado' => 'abierta', 'cerrada_at' => null]);
        }
    }
}
