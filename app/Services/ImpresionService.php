<?php

namespace App\Services;

use App\Support\NumeroALetras;
use App\Exceptions\NegocioException;
use App\Models\Caja;
use App\Models\ConfiguracionNegocio;
use App\Models\Impresora;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\TrabajoImpresion;
use App\Models\TurnoCaja;
use App\Models\Venta;
use App\Support\Impresion\EscPos;
use App\Support\Impresion\ImpresionException;
use App\Support\Impresion\StarRaster;
use App\Support\Impresion\Transporte;
use App\Support\Impresion\TransporteRed;
use App\Support\Impresion\TransporteWindows;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Arma los documentos (ticket, comanda, cierre, prueba) en ESC/POS, los envía
 * a la impresora y deja cada intento en trabajos_impresion para poder ver los
 * errores y reintentar. Imprimir nunca bloquea una venta: si falla, el POS
 * ofrece imprimir desde el navegador.
 */
class ImpresionService
{
    // ── Selección de impresora ──────────────────────────────────

    /**
     * Impresora de tickets para una caja: la asignada a la caja o, si no tiene,
     * la marcada como predeterminada.
     */
    public static function impresoraDeTickets(?int $cajaId): ?Impresora
    {
        if ($cajaId) {
            $caja = Caja::with('impresora')->find($cajaId);
            if ($caja?->impresora && $caja->impresora->activo && $caja->impresora->imprimeTickets()) {
                return $caja->impresora;
            }
        }

        return Impresora::where('activo', true)
            ->whereIn('uso', ['ticket', 'ambos'])
            ->orderByDesc('predeterminada')
            ->orderBy('id')
            ->first();
    }

    // ── Documentos ─────────────────────────────────────────────

    /**
     * Ticket de venta (y comandas por categoría si se piden).
     * @return TrabajoImpresion[]
     */
    public static function imprimirVenta(Venta $venta, ?int $cajaId, ?int $usuarioId, bool $copia = false, bool $conComandas = false): array
    {
        $trabajos = [];
        $impresora = self::impresoraDeTickets($cajaId ?? $venta->caja_id);

        if (!$impresora) {
            throw new NegocioException('No hay impresora de tickets configurada para esta caja');
        }

        $venta->loadMissing(['detalles', 'pagos.metodoPago', 'usuario', 'caja', 'cliente', 'pedido', 'cuentaCobrar']);

        $doc = self::ticket($venta, $impresora, $copia);

        // El cajón se abre solo en la venta original pagada (en parte) en efectivo
        $conEfectivo = $venta->pagos->contains(fn ($p) => $p->metodoPago?->afecta_efectivo);
        if (!$copia && $conEfectivo && $impresora->cash_drawer !== 'ninguno') {
            $doc->abrirCajon($impresora->cash_drawer);
        }

        $trabajos[] = self::enviar($impresora, $doc, 'ticket', 'venta', $venta->id, $usuarioId);

        if ($conComandas && !$copia) {
            $venta->loadMissing('detalles.producto:id,nombre');
            // Con extras: el nombre solo del producto y cada extra en su renglón («* Leche»)
            $trabajos = array_merge($trabajos, self::comandas($venta->detalles->map(fn ($d) => [
                'producto_id' => $d->producto_id,
                'nombre'      => $d->extras ? ($d->producto?->nombre ?? $d->nombre_producto) : $d->nombre_producto,
                'cantidad'    => (float) $d->cantidad,
                'unidad'      => $d->unidad,
                'notas'       => $d->extras ?? [],
            ])->all(), ['numero' => $venta->numero, 'lugar' => 'Mostrador', 'cuenta' => $venta->cliente?->nombre],
                'venta', $venta->id, $usuarioId, $impresora));
        }

        return $trabajos;
    }

    /**
     * Comandas de una precuenta o pedido: un ticket por área de preparación.
     * @return TrabajoImpresion[]
     */
    public static function imprimirComandasPedido(Pedido $pedido, ?int $usuarioId): array
    {
        $pedido->loadMissing('detalles');

        $trabajos = self::comandas($pedido->detalles->map(fn ($d) => [
            'producto_id' => $d->producto_id,
            'nombre'      => $d->nombre_producto,
            'cantidad'    => (float) $d->cantidad,
            'unidad'      => null,
            'notas'       => $d->notas,
        ])->all(), ['numero' => $pedido->numero, 'lugar' => 'Pedido especial', 'cuenta' => $pedido->nombre],
            'pedido', $pedido->id, $usuarioId, self::impresoraDeTickets(null), true);

        if (!$trabajos) {
            throw new NegocioException('No hay impresora de comandas ni de tickets configurada');
        }

        return $trabajos;
    }

    /**
     * Comanda de una mesa: cada área (Cocina, Barra) sale en su propio ticket, con
     * el nombre del área en grande, para entregarlo a quien prepara. Va a la impresora
     * del área; si no tiene, a la de comandas o de tickets (una sola tablet con una
     * impresora). Los extras y las notas van debajo de cada línea.
     * @return TrabajoImpresion[]
     */
    public static function imprimirComanda(\App\Models\Comanda $comanda, ?int $usuarioId, bool $reimpresion = false): array
    {
        $comanda->loadMissing(['orden.terminal', 'area.impresora', 'usuario:id,nombre', 'items' => fn ($q) => $q->where('estado', '!=', 'cancelado')]);

        $items = $comanda->items->map(fn ($i) => [
            'producto_id' => $i->producto_id,
            'nombre'      => $i->nombre_producto,
            'cantidad'    => (float) $i->cantidad,
            'unidad'      => null,
            'notas'       => self::notasDeItem($i),
        ])->all();

        if (!$items) {
            return [];
        }

        // La cuenta de la mesa ("Ticket 1") sale centrada, como en el formato del negocio
        $cuentas = $comanda->items->pluck('ticket_id')->unique();
        $cuenta = $cuentas->count() === 1 ? \App\Models\OrdenTicket::find($cuentas->first())?->nombre : null;
        $terminal = $comanda->orden->terminal;

        $datos = [
            'numero' => $comanda->numero,
            'area'   => $comanda->area?->nombre,
            'lugar'  => self::lugar($terminal),
            'cuenta' => $cuenta,
            'extra'  => $comanda->notas ? ["Nota: {$comanda->notas}"] : [],
            'marca'  => $reimpresion ? 'COPIA' : 'ORIGINAL',
        ];

        $impresora = self::impresoraDeArea($comanda->area)
            ?? self::impresoraDeTickets($terminal->caja_id);

        return $impresora
            ? [self::enviar($impresora, self::comanda($items, $impresora, $datos), 'comanda', 'comanda', $comanda->id, $usuarioId)]
            : [];
    }

    // "Mesa: MESA 1" para las mesas, "Mostrador" para los pedidos de caja
    private static function lugar(\App\Models\Terminal $terminal): string
    {
        return match ($terminal->tipo) {
            'mesa'  => "Mesa: {$terminal->nombre}",
            'caja'  => 'Mostrador', // igual que la venta rápida
            default => $terminal->nombre,
        };
    }

    /**
     * Aviso a cocina o barra de lo que se canceló después de enviarlo: un ticket
     * "CANCELADO" por área, para que no lo preparen. Nunca frena la cancelación.
     * @param \App\Models\OrdenItem[] $items
     * @return TrabajoImpresion[]
     */
    public static function imprimirCancelacion(array $items, string $motivo, \App\Models\Administrador $usuario, \App\Models\Administrador $autorizador): array
    {
        $trabajos = [];
        $enviados = collect($items)->filter(fn ($i) => $i->comanda_id);

        foreach ($enviados->groupBy('comanda_id') as $grupo) {
            $primero = $grupo->first();
            $primero->loadMissing(['comanda.area.impresora', 'orden.terminal']);
            $comanda = $primero->comanda;
            $terminal = $primero->orden->terminal;

            $lineas = $grupo->map(fn ($i) => [
                'producto_id' => $i->producto_id,
                'nombre'      => $i->nombre_producto,
                'cantidad'    => (float) $i->cantidad,
                'unidad'      => null,
                'notas'       => self::notasDeItem($i),
            ])->all();

            $extra = array_values(array_filter([
                "Mesero: {$usuario->nombre}",
                $autorizador->id !== $usuario->id ? "Autorizó: {$autorizador->nombre}" : null,
                "Motivo: {$motivo}",
            ]));

            try {
                $impresora = self::impresoraDeArea($comanda->area) ?? self::impresoraDeTickets($terminal->caja_id);
                if ($impresora) {
                    $doc = self::comanda($lineas, $impresora, [
                        'numero'    => $comanda->numero,
                        'area'      => $comanda->area?->nombre,
                        'lugar'     => self::lugar($terminal),
                        'extra'     => $extra,
                        'cancelado' => true,
                        'marca'     => 'CANCELADO',
                    ]);
                    $trabajos[] = self::enviar($impresora, $doc, 'comanda', 'comanda', $comanda->id, $usuario->id);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $trabajos;
    }

    // Debajo de cada línea, una por renglón con "*": los extras, lo que no lleva y la nota
    private static function notasDeItem(\App\Models\OrdenItem $i): array
    {
        return collect(\App\Models\Modificador::textos($i->modificadores))
            ->merge($i->textoAjustes())
            ->when($i->notas, fn ($c) => $c->push($i->notas))->values()->all();
    }

    /**
     * Precuenta de una mesa (toda la orden o una cuenta) en la impresora de tickets.
     * Lleva lo que falta por cobrar; no es comprobante de pago. La usa la app de la tablet.
     */
    public static function imprimirPrecuenta(\App\Models\Orden $orden, ?int $ticketId, ?int $cajaId, ?int $usuarioId): TrabajoImpresion
    {
        $orden->loadMissing(['terminal', 'tickets', 'items' => fn ($q) => $q->where('estado', '!=', 'cancelado')->whereNull('venta_id')]);
        $impresora = self::impresoraDeTickets($cajaId ?? $orden->terminal->caja_id);
        if (!$impresora) {
            throw new NegocioException('No hay impresora de tickets configurada para esta caja');
        }

        $items = $orden->items->when($ticketId, fn ($c) => $c->where('ticket_id', $ticketId));
        if ($items->isEmpty()) {
            throw new NegocioException('No hay nada por cobrar en esta cuenta');
        }

        $negocio = ConfiguracionNegocio::actual();
        $dinero = fn ($v) => '$' . number_format((float) $v, 2);
        $cuenta = $ticketId ? $orden->tickets->firstWhere('id', $ticketId)?->nombre : null;

        $doc = new EscPos($impresora->ancho_caracteres);
        $doc->alinear('centro')->negrita()->linea($negocio->nombre)->grande(true, false)->linea('PRECUENTA')->normal()
            ->alinear('izquierda')->separador()
            ->columnas($orden->terminal->nombre . ($cuenta ? " · {$cuenta}" : ''), now()->format('d-m-Y H:i'))
            ->separador();

        $total = 0.0;
        foreach ($items as $i) {
            $cantidad = rtrim(rtrim(number_format((float) $i->cantidad, 3, '.', ''), '0'), '.');
            $subtotal = round((float) $i->cantidad * (float) $i->precio_unitario, 2);
            $total += $subtotal;
            $doc->parrafo($i->nombre_producto);
            // Extras y cambios debajo, un renglón cada uno con «*», como en la comanda
            foreach ($i->modificadores ?? [] as $m) {
                $veces = max(1, (int) ($m['cantidad'] ?? 1));
                $doc->parrafo('  * ' . $m['opcion'] . ($veces > 1 ? " ×{$veces}" : '')
                    . ((float) ($m['precio'] ?? 0) > 0 ? ' (+' . $dinero((float) $m['precio'] * $veces) . ')' : ''));
            }
            foreach ($i->textoAjustes() as $ajuste) {
                $doc->parrafo("  * {$ajuste}");
            }
            $doc->columnas("  {$cantidad} x " . $dinero($i->precio_unitario), $dinero($subtotal));
        }

        $doc->separador()->negrita()->grande(true, false)->columnas('TOTAL', $dinero($total))->normal()
            ->alinear('centro')->separador()->linea('Este documento no es comprobante de pago');

        return self::enviar($impresora, $doc, 'precuenta', 'orden', $orden->id, $usuarioId);
    }

    public static function imprimirCierre(TurnoCaja $turno, ?int $cajaId, ?int $usuarioId): TrabajoImpresion
    {
        $impresora = self::impresoraDeTickets($cajaId ?? $turno->caja_id);
        if (!$impresora) {
            throw new NegocioException('No hay impresora de tickets configurada para esta caja');
        }

        return self::enviar($impresora, self::cierre($turno, $impresora), 'cierre', 'turno', $turno->id, $usuarioId);
    }

    /**
     * Prueba del ticket de producción con datos de ejemplo (Mesa 1, Ice latte con extras).
     * Usa el mismo comanda() que cocina y barra: lo que se vea aquí es lo que sale de verdad.
     */
    public static function pruebaProduccion(Impresora $impresora, ?int $usuarioId): TrabajoImpresion
    {
        $doc = self::comanda([
            ['producto_id' => null, 'nombre' => 'Ice latte', 'cantidad' => 1, 'unidad' => null,
                'notas' => ['saborizante caramelo', 'leche deslactosada']],
        ], $impresora, [
            'numero' => '031719',
            'area'   => 'Barra',
            'lugar'  => 'Mesa: MESA 1',
            'cuenta' => 'Ticket 1',
            'marca'  => 'ORIGINAL',
        ]);

        return self::enviar($impresora, $doc, 'prueba', null, null, $usuarioId);
    }

    public static function prueba(Impresora $impresora, ?int $usuarioId): TrabajoImpresion
    {
        $negocio = ConfiguracionNegocio::actual();
        $doc = new EscPos($impresora->ancho_caracteres);

        $doc->alinear('centro')->negrita()->grande()->linea($negocio->nombre)->normal()
            ->linea('PRUEBA DE IMPRESIÓN')
            ->separador()
            ->alinear('izquierda')
            ->columnas('Impresora', $impresora->alias)
            ->columnas('Conexión', $impresora->conexion === 'windows'
                ? (string) $impresora->nombre_windows
                : "{$impresora->ip_impresora}:{$impresora->puerto}" . ($impresora->conexion === 'dispositivo' ? ' (tablet)' : ''))
            ->columnas('Papel', $impresora->ancho_caracteres >= 48 ? '80 mm' : '58 mm')
            ->columnas('Fecha', now()->format('d-m-Y H:i'))
            ->separador()
            ->linea('Acentos: áéíóú ÁÉÍÓÚ ñÑ ü ¿? ¡!')
            // Regla del ancho exacto del papel: debe caber en una sola línea
            ->linea(substr(str_repeat('1234567890', 10), 0, $impresora->ancho_caracteres))
            ->separador()
            ->alinear('centro')->negrita()->linea('¡Impresión correcta!')->normal();

        if ($impresora->cash_drawer !== 'ninguno') {
            $doc->abrirCajon($impresora->cash_drawer);
        }

        return self::enviar($impresora, $doc, 'prueba', null, null, $usuarioId);
    }

    public static function abrirCajon(?int $cajaId, ?int $usuarioId): TrabajoImpresion
    {
        $impresora = self::impresoraDeTickets($cajaId);
        if (!$impresora || $impresora->cash_drawer === 'ninguno') {
            throw new NegocioException('La impresora de esta caja no tiene cajón de dinero configurado');
        }

        $doc = (new EscPos($impresora->ancho_caracteres))->abrirCajon($impresora->cash_drawer);

        return self::enviar($impresora, $doc, 'cajon', null, null, $usuarioId, cortar: false);
    }

    /**
     * Vuelve a enviar un trabajo que falló (mismo contenido, a la misma impresora
     * o a otra si se indica).
     */
    public static function reintentar(TrabajoImpresion $trabajo, ?Impresora $otra = null): TrabajoImpresion
    {
        $impresora = $otra ?? $trabajo->impresora;
        if (!$impresora || !$impresora->activo) {
            throw new NegocioException('La impresora de este trabajo ya no está disponible: elige otra');
        }

        $trabajo->impresora_id = $impresora->id;
        self::transmitir($trabajo, $impresora, base64_decode($trabajo->contenido));

        return $trabajo;
    }

    // ── Plantillas ─────────────────────────────────────────────

    /** Columnas para el ticket de cobro: más grande y legible que las comandas en la Star de 80 mm. */
    public const COLUMNAS_TICKET_STAR = 42;

    /**
     * En la Star (imprime imágenes) el ticket de cobro va a 42 columnas en papel de 80 mm:
     * la letra sale ~14 % más grande. Las impresoras ESC/POS tienen la letra fija en el equipo.
     */
    public static function anchoTicket(Impresora $impresora): int
    {
        $ancho = (int) $impresora->ancho_caracteres;

        return $impresora->tipo_impresion === 'star_raster' && $ancho > self::COLUMNAS_TICKET_STAR
            ? self::COLUMNAS_TICKET_STAR
            : $ancho;
    }

    public static function ticket(Venta $venta, Impresora $impresora, bool $copia = false): EscPos
    {
        $negocio = ConfiguracionNegocio::actual();
        $ancho = self::anchoTicket($impresora);
        $doc = new EscPos($ancho);
        if ($ancho !== (int) $impresora->ancho_caracteres) {
            $doc->anchoRaster(); // la Star lo dibuja con letra más grande (menos columnas en el mismo papel)
        }
        $numero = fn ($v) => number_format((float) $v, 2, '.', '');

        // ── Encabezado del emisor (como el recibo que ya usa el negocio) ──
        $doc->alinear('centro')->negrita()->grande(true, false)->parrafo($negocio->razon_social ?: $negocio->nombre)->normal();
        if ($negocio->direccion) {
            $doc->parrafo($negocio->direccion);
        }
        if ($negocio->razon_social) {
            $doc->parrafo($negocio->nombre);
        }
        if ((string) $negocio->nit !== "") {
            $doc->negrita()->linea("NIT: {$negocio->nit}")->normal();
        }
        if ((string) $negocio->nrc !== "") {
            $doc->negrita()->linea("NRC: {$negocio->nrc}")->normal();
        }
        $doc->parrafo('Actividad económica:' . ($negocio->actividad_economica ? " {$negocio->actividad_economica}" : ''));
        $doc->linea('Tipo de establecimiento:');
        $doc->parrafo($negocio->tipo_establecimiento ?: 'Sucursal / Agencia');
        if ($negocio->telefono) {
            $doc->linea("Tel: {$negocio->telefono}");
        }

        // ── Documento ──
        $doc->avanzar()->separador('=')->avanzar()
            ->negrita()->linea('Recibo')->normal();
        if ($copia) {
            $doc->negrita()->linea('*** COPIA ***')->normal();
        }
        if ($venta->estado === 'anulada') {
            $doc->negrita()->linea('*** ANULADA ***')->normal();
        }
        $doc->linea('Fecha y hora de generación:')
            ->negrita()->linea($venta->fecha_venta->format('d-m-Y H:i'))->normal()
            ->alinear('izquierda');

        // ── Receptor ──
        $doc->avanzar()->separador('=')->avanzar()
            ->alinear('centro')->negrita()->grande(true, false)->linea('Datos del receptor')->normal()
            ->alinear('izquierda')
            ->parrafo('Nombre: ' . ($venta->cliente?->nombre ?: 'Consumidor Final'));
        if ($venta->cliente?->documento) {
            $doc->parrafo("DUI/NIT: {$venta->cliente->documento}");
        }
        $doc->parrafo('Dirección: ' . ($venta->cliente?->direccion ?: 'Ciudad'))
            ->linea("No. de Venta: {$venta->numero}");
        if ($venta->pedido?->tipo === 'especial') {
            $doc->linea("Pedido: {$venta->pedido->numero}");
        }

        // ── Detalle: Cant | Descripción | Precio | Total ──
        $cCant = 6;
        $cNum = $ancho >= 42 ? 8 : 7;
        $cDesc = $ancho - $cCant - $cNum * 2;
        $fila = function (string $cant, string $desc, string $precio, string $total) use ($cCant, $cDesc, $cNum) {
            return str_pad(mb_substr($cant, 0, $cCant), $cCant)
                . self::rellenar(mb_substr($desc, 0, $cDesc), $cDesc)
                . str_pad(mb_substr($precio, 0, $cNum), $cNum, ' ', STR_PAD_LEFT)
                . str_pad(mb_substr($total, 0, $cNum), $cNum, ' ', STR_PAD_LEFT);
        };

        $doc->avanzar()->separador('=')->avanzar()
            ->negrita()->linea($fila('Cant', 'Descripción', 'Precio', 'Total'))->normal();
        foreach ($venta->detalles as $d) {
            // La descripción larga sigue en las líneas de abajo, en su misma columna
            // Con extras: el producto solo y cada extra en su renglón («* Leche»)
            [$nombre, $extras] = $d->partesTicket();
            $partes = explode("\n", wordwrap($nombre, max($cDesc - 1, 8), "\n", true));
            $doc->linea($fila($numero($d->cantidad), array_shift($partes), $numero($d->precio_unitario), $numero((float) $d->cantidad * (float) $d->precio_unitario)));
            foreach ($partes as $resto) {
                $doc->linea(str_repeat(' ', $cCant) . $resto);
            }
            foreach ($extras as $extra) {
                $renglones = explode("\n", wordwrap((string) $extra, max($cDesc - 3, 8), "\n", true));
                $doc->linea(str_repeat(' ', $cCant) . '* ' . array_shift($renglones));
                foreach ($renglones as $resto) {
                    $doc->linea(str_repeat(' ', $cCant + 2) . $resto);
                }
            }
            if ((float) $d->descuento > 0) {
                $doc->linea($fila('', 'Descuento', '', '-' . $numero($d->descuento)));
            }
        }

        $doc->alinear('derecha');
        if ((float) $venta->descuento_total > 0) {
            $doc->linea('Subtotal: $ ' . $numero((float) $venta->subtotal + (float) $venta->descuento_lineas));
            if ((float) $venta->descuento_global > 0) {
                $doc->linea('Descuento: -$ ' . $numero($venta->descuento_global));
            }
        }
        $doc->negrita()->linea('Total: $ ' . $numero($venta->total))->normal();
        if ((float) $venta->anticipo_aplicado > 0) {
            $doc->linea('Anticipo recibido: $ ' . $numero($venta->anticipo_aplicado))
                ->linea('Pagado hoy: $ ' . $numero((float) $venta->total - (float) $venta->anticipo_aplicado));
        }

        $doc->alinear('izquierda')
            ->linea('Total en letras:')
            ->parrafo(NumeroALetras::dolares((float) $venta->total))
            ->parrafo('Condición de la operación: ' . self::condicion($venta));
        foreach ($venta->pagos as $p) {
            if ($p->referencia) {
                $doc->parrafo(($p->metodoPago?->nombre ?? 'Pago') . " ref. {$p->referencia}");
            }
        }
        if ((float) $venta->cambio > 0) {
            $doc->linea('Efectivo recibido: $ ' . $numero($venta->pagos->sum(fn ($p) => (float) ($p->monto_recibido ?? 0))))
                ->linea('Cambio: $ ' . $numero($venta->cambio));
        }
        if ($venta->cuentaCobrar) {
            // Venta al crédito: el cliente firma el recibo
            $doc->linea('Crédito: vence el ' . $venta->cuentaCobrar->fecha_vencimiento->format('d-m-Y'))
                ->avanzar(2)->alinear('centro')
                ->linea('______________________________')->linea('Firma del cliente')->alinear('izquierda');
        }

        $doc->avanzar()->separador('=')->avanzar()->alinear('centro');
        $doc->parrafo($negocio->mensaje_ticket ?: 'Muchas gracias por su compra.');

        return $doc;
    }

    // Contado (Efectivo, Tarjeta, Transferencia...) o Crédito, como dice el recibo
    public static function condicion(Venta $venta): string
    {
        $metodos = $venta->pagos->map(fn ($p) => $p->metodoPago?->nombre)->filter()->unique()->values();
        if ($venta->anticipo_aplicado > 0 && $metodos->isEmpty()) {
            return 'Anticipo';
        }
        return $metodos->isEmpty() ? 'Efectivo' : $metodos->join(', ', ' y ');
    }

    // str_pad que cuenta letras con tilde como un solo carácter
    private static function rellenar(string $texto, int $ancho): string
    {
        return $texto . str_repeat(' ', max($ancho - mb_strlen($texto), 0));
    }

    /**
     * Comanda (solicitud de producción) con el formato del negocio, sin precios:
     *   #031719 / THE FROZEN BAR / Solicitud de producción / área
     *   Mesa: MESA 1, fecha a la izquierda y hora a la derecha, ====, la cuenta
     *   ("Ticket 1") centrada, CANT  DESCRIPCIÓN con los extras debajo ("* leche
     *   deslactosada") y al final ORIGINAL o COPIA.
     * @param array $items [['producto_id','nombre','cantidad','unidad','notas' (texto o lista)], ...]
     * @param array $datos numero, area, lugar, cuenta, extra[], marca, cancelado
     */
    public static function comanda(array $items, Impresora $impresora, array $datos = []): EscPos
    {
        $ancho = $impresora->ancho_caracteres;
        $doc = new EscPos($ancho);
        $negocio = ConfiguracionNegocio::actual();
        $cCant = 8; // ancho de la columna CANT
        $cDesc = $ancho - $cCant;

        // Todo en negrita y doble alto (letra alta y angosta como la del negocio; el ancho de columnas no cambia)
        $alto = fn () => $doc->negrita()->grande(true, false);

        $doc->alinear('centro');
        if (!empty($datos['numero'])) {
            $doc->negrita()->grande(true, false)->linea('#' . $datos['numero']);
        }
        $doc->negrita()->grande(true, false)->parrafo(mb_strtoupper($negocio->nombre ?? 'The Frozen Bar'), $ancho);
        $alto()->linea('Solicitud de producción');
        // Cada área sale en su propio ticket: el nombre en grande dice a quién le toca
        // (Cocina y Barra pueden imprimir en la misma impresora). Sin área: comanda general.
        if (!empty($datos['area'])) {
            $doc->avanzar()->negrita()->grande()->linea('** ' . mb_strtoupper($datos['area']) . ' **');
        }
        if (!empty($datos['cancelado'])) {
            $doc->avanzar()->negrita()->grande()->linea('** CANCELADO **');
            $alto()->linea('No preparar lo siguiente');
        }

        $alto();
        $doc->alinear('izquierda')->avanzar();
        if (!empty($datos['lugar'])) {
            $doc->parrafo($datos['lugar'], $ancho);
        }
        foreach ($datos['extra'] ?? [] as $linea) {
            $doc->parrafo($linea, $ancho);
        }
        $doc->avanzar()->linea(now()->format('d-m-Y'))
            ->alinear('derecha')->linea(now()->format('H:i'))->alinear('izquierda')
            ->normal()->separador('=');
        $alto();

        if (!empty($datos['cuenta'])) {
            $doc->avanzar()->alinear('centro')->linea($datos['cuenta'])->alinear('izquierda');
        }
        $doc->avanzar()->linea(self::rellenar('CANT', $cCant) . 'DESCRIPCIÓN');

        foreach ($items as $i) {
            $cantidad = rtrim(rtrim(number_format((float) $i['cantidad'], 3, '.', ''), '0'), '.');
            $unidad = $i['unidad'] && $i['unidad'] !== 'u' ? " {$i['unidad']}" : '';
            foreach (self::envolver($i['nombre'], $cDesc) as $n => $texto) {
                $doc->linea(self::rellenar($n === 0 ? $cantidad . $unidad : '', $cCant) . $texto);
            }
            $notas = is_array($i['notas'] ?? null) ? $i['notas'] : array_filter([$i['notas'] ?? null]);
            foreach ($notas as $nota) {
                foreach (self::envolver($nota, $cDesc - 2) as $n => $texto) {
                    $doc->linea(str_repeat(' ', $cCant) . ($n === 0 ? '* ' : '  ') . $texto);
                }
            }
        }

        $doc->avanzar()->alinear('centro')->linea($datos['marca'] ?? 'ORIGINAL')->normal()->alinear('izquierda');

        return $doc;
    }

    // Parte un texto en renglones de $ancho sin cortar palabras (cuenta las tildes como una letra)
    private static function envolver(string $texto, int $ancho): array
    {
        $lineas = [''];
        foreach (preg_split('/\s+/u', trim($texto)) as $palabra) {
            $actual = end($lineas);
            $junto = $actual === '' ? $palabra : "{$actual} {$palabra}";
            if (mb_strlen($junto) <= $ancho || $actual === '') {
                $lineas[count($lineas) - 1] = $junto;
            } else {
                $lineas[] = $palabra;
            }
        }
        return $lineas;
    }

    public static function cierre(TurnoCaja $turno, Impresora $impresora): EscPos
    {
        $negocio = ConfiguracionNegocio::actual();
        $turno->loadMissing(['caja', 'usuarioApertura', 'usuarioCierre', 'arqueos']);
        $resumen = CajaService::resumen($turno);
        $dinero = fn ($v) => '$' . number_format((float) $v, 2);

        $doc = new EscPos($impresora->ancho_caracteres);
        $doc->alinear('centro')->negrita()->linea($negocio->nombre)
            ->grande(true, false)->linea($turno->estaAbierto() ? 'CORTE PARCIAL' : 'CIERRE DE CAJA')->normal()
            ->linea("{$turno->caja?->nombre} · Turno #{$turno->id}")
            ->alinear('izquierda')->separador()
            ->columnas('Apertura', $turno->abierto_at->format('d-m-Y H:i'))
            ->columnas('Abrió', $turno->usuarioApertura?->nombre ?? '');

        if ($turno->cerrado_at) {
            $doc->columnas('Cierre', $turno->cerrado_at->format('d-m-Y H:i'))
                ->columnas('Cerró', $turno->usuarioCierre?->nombre ?? '');
        }

        $doc->separador()
            ->columnas('Fondo inicial', $dinero($resumen['fondo_inicial']))
            ->columnas('Ventas', $dinero($resumen['totales']['ventas']));
        if ($resumen['totales']['devoluciones'] > 0) {
            $doc->columnas('Devoluciones', '-' . $dinero($resumen['totales']['devoluciones']));
        }
        $doc->columnas('Ingresos', $dinero($resumen['totales']['ingresos']))
            ->columnas('Egresos', '-' . $dinero($resumen['totales']['egresos']))
            ->separador()->negrita()->linea('Por forma de pago')->normal();

        foreach ($resumen['por_metodo'] as $m) {
            $doc->columnas($m['nombre'], $dinero($m['neto']));
        }

        if ($resumen['ventas_por_usuario']) {
            $doc->separador()->negrita()->linea('Ventas por empleado')->normal();
            foreach ($resumen['ventas_por_usuario'] as $v) {
                $doc->columnas("{$v['usuario']} ({$v['cantidad']})", $dinero($v['total']));
            }
        }

        // Corte parcial con cierre ciego: quien imprime sin ver el historial de cierres no ve
        // cuánto debería haber (igual que en pantalla); el cierre ya contado sí lo muestra
        $doc->separador()->negrita();
        if ($turno->estaAbierto() && CajaService::ocultarEsperado()) {
            $doc->linea('Efectivo esperado: oculto (cierre ciego)');
        } else {
            $doc->columnas('Efectivo esperado', $dinero($turno->efectivo_esperado ?? $resumen['efectivo_esperado']));
        }
        if ($turno->efectivo_contado !== null) {
            $diferencia = (float) $turno->diferencia;
            $doc->columnas('Efectivo contado', $dinero($turno->efectivo_contado))
                ->columnas($diferencia < 0 ? 'FALTANTE' : ($diferencia > 0 ? 'SOBRANTE' : 'DIFERENCIA'), $dinero(abs($diferencia)));
        }
        $doc->normal();
        if ($turno->efectivo_contado !== null) {
            // El efectivo no se retira: lo contado es el fondo del siguiente turno
            $doc->columnas('Queda para el sig. turno', $dinero($turno->efectivo_contado));
        }

        $conteo = $turno->arqueos->firstWhere('tipo', 'cierre')?->denominaciones;
        if ($conteo) {
            $doc->separador()->linea('Conteo');
            krsort($conteo, SORT_NUMERIC);
            foreach ($conteo as $valor => $cantidad) {
                $doc->columnas("  {$cantidad} x " . $dinero($valor), $dinero((float) $valor * $cantidad));
            }
        }

        if ($turno->observaciones_cierre) {
            $doc->separador()->parrafo("Obs.: {$turno->observaciones_cierre}");
        }

        // Lo que el siguiente turno tiene que comprar: solo lo que quedó en su mínimo o debajo.
        // Se puede apagar en Datos del negocio (la lista sigue en pantalla y en Control de existencias).
        if ($negocio->ticket_por_comprar) {
            $cantidad = fn ($v, $u) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') . ($u ? " {$u}" : '');
            $doc->separador()->negrita()->linea('POR COMPRAR')->normal();
            if (!$resumen['por_comprar']) {
                $doc->linea('Nada bajo el mínimo');
            }
            foreach ($resumen['por_comprar'] as $p) {
                $doc->columnas($p['producto'], 'hay ' . $cantidad($p['stock_actual'], $p['unidad']) . ' / mín ' . $cantidad($p['stock_minimo'], null));
            }
        }

        $mitad = intdiv($impresora->ancho_caracteres, 2) - 2;
        $doc->avanzar(3)
            ->linea(str_repeat('_', $mitad) . '    ' . str_repeat('_', $mitad))
            ->linea(str_pad('Cajero', $mitad + 4) . 'Supervisor');

        return $doc;
    }

    // ── Envío ──────────────────────────────────────────────────

    /**
     * Reparte los productos por área de preparación (la de su categoría) y manda un
     * ticket por área a su impresora. Lo que no tiene área se entrega directo y no
     * imprime comanda, salvo $conGeneral (pedidos especiales: todo es encargo a producción
     * y sale en una comanda general). Sin impresora de comandas se usa $respaldo (la de tickets).
     * @return TrabajoImpresion[]
     */
    private static function comandas(array $items, array $datos, string $documentoTipo, int $documentoId, ?int $usuarioId, ?Impresora $respaldo = null, bool $conGeneral = false): array
    {
        $productos = Producto::with('categorias.area.impresora')
            ->whereIn('id', collect($items)->pluck('producto_id'))
            ->get()
            ->keyBy('id');
        $areaPorProducto = $productos->map(fn (Producto $p) => $p->areaDeComanda());
        // Lo que se entrega directo (imprime_comanda apagado) no sale ni en la comanda general
        $items = collect($items)->reject(fn ($i) => $productos[$i['producto_id']]?->imprime_comanda === false)->values()->all();

        $trabajos = [];
        foreach (collect($items)->groupBy(fn ($i) => $areaPorProducto[$i['producto_id']]?->id) as $areaId => $delArea) {
            if (!$areaId && !$conGeneral) {
                continue; // sin área: se entrega directo, solo lleva el ticket de venta
            }
            $area = $areaId ? $areaPorProducto[$delArea->first()['producto_id']] : null;
            $impresora = self::impresoraDeArea($area) ?? $respaldo;
            if ($impresora) {
                $trabajos[] = self::enviar($impresora, self::comanda($delArea->all(), $impresora, $datos + ['area' => $area?->nombre]),
                    'comanda', $documentoTipo, $documentoId, $usuarioId);
            }
        }

        return $trabajos;
    }

    /**
     * Lo que la tablet necesita para imprimir sola cuando no hay servidor: los datos del
     * negocio para el encabezado, la impresora de tickets de la caja y a qué impresora va
     * la comanda de cada producto (por su área de preparación).
     */
    public static function configuracionOffline(?int $cajaId): array
    {
        $negocio = ConfiguracionNegocio::actual();
        $dato = fn (?Impresora $i) => $i ? [
            'id'               => $i->id,
            'alias'            => $i->alias,
            'conexion'         => $i->conexion,
            'ip'               => $i->ip_impresora,
            'puerto'           => (int) ($i->puerto ?: 9100),
            'tipo_impresion'   => $i->tipo_impresion,
            'ancho_caracteres' => (int) $i->ancho_caracteres,
            'cortar_papel'     => (bool) $i->cortar_papel,
            'cash_drawer'      => $i->cash_drawer,
        ] : null;

        $areas = \App\Models\AreaPreparacion::with('impresora')->where('activo', true)->get();
        $productos = Producto::with('categorias:id,area_preparacion_id')
            ->where('se_vende', true)
            ->where('imprime_comanda', true) // los que se entregan directo no llevan comanda
            ->get(['id'])
            ->mapWithKeys(fn ($p) => [$p->id => $p->categorias
                ->map(fn ($c) => $c->area_preparacion_id)
                ->first(fn ($id) => $id && $areas->contains('id', $id))])
            ->filter();

        return [
            'negocio' => [
                'nombre'               => $negocio->nombre,
                'razon_social'         => $negocio->razon_social,
                'direccion'            => $negocio->direccion,
                'nit'                  => (string) $negocio->nit,
                'nrc'                  => (string) $negocio->nrc,
                'actividad_economica'  => $negocio->actividad_economica,
                'tipo_establecimiento' => $negocio->tipo_establecimiento,
                'telefono'             => $negocio->telefono,
                'mensaje_ticket'       => $negocio->mensaje_ticket,
            ],
            'ticket'   => $dato(self::impresoraDeTickets($cajaId)),
            'areas'    => $areas->map(fn ($a) => ['id' => $a->id, 'nombre' => $a->nombre, 'impresora' => $dato(self::impresoraDeArea($a))])->values(),
            'productos' => $productos,
        ];
    }

    // La impresora del área; si no tiene, la de comandas predeterminada
    private static function impresoraDeArea(?\App\Models\AreaPreparacion $area): ?Impresora
    {
        $propia = $area?->impresora;
        if ($propia && $propia->activo) {
            return $propia;
        }

        return Impresora::where('activo', true)->whereIn('uso', ['comanda', 'ambos'])
            ->orderByDesc('predeterminada')->orderBy('id')->first();
    }

    private static function enviar(Impresora $impresora, EscPos $doc, string $tipo, ?string $documentoTipo, ?int $documentoId, ?int $usuarioId, bool $cortar = true): TrabajoImpresion
    {
        if ($cortar && $impresora->cortar_papel) {
            $doc->cortar();
        } elseif ($cortar) {
            $doc->avanzar(4);
        }

        $trabajo = TrabajoImpresion::create([
            'impresora_id'   => $impresora->id,
            'tipo'           => $tipo,
            'documento_tipo' => $documentoTipo,
            'documento_id'   => $documentoId,
            'estado'         => 'pendiente',
            'contenido'      => base64_encode($doc->bytes()),
            'usuario_id'     => $usuarioId,
        ]);

        self::transmitir($trabajo, $impresora, $doc->bytes());

        return $trabajo;
    }

    private static function transmitir(TrabajoImpresion $trabajo, Impresora $impresora, string $bytes): void
    {
        // Servidor en la nube: no llega a la red del local. El trabajo queda en la cola y lo
        // imprime la primera tablet con sesión que revise la cola (todas imprimen; ver tomarCola).
        if ($impresora->conexion === 'dispositivo') {
            $trabajo->estado = 'pendiente';
            $trabajo->error = null;
            $trabajo->save();
            $trabajo->touch();
            Cache::forget(self::CLAVE_TOMADO . $trabajo->id);
            return;
        }

        $trabajo->intentos++;

        try {
            // Se guarda siempre en ESC/POS; las Star TSP100 lo reciben convertido a imagen
            if ($impresora->tipo_impresion === 'star_raster') {
                $bytes = StarRaster::desdeEscPos($bytes, $impresora->ancho_caracteres);
            }
            self::transporte($impresora)->enviar($impresora, $bytes);
            $trabajo->estado = 'impreso';
            $trabajo->error = null;
            $trabajo->impreso_at = now();
        } catch (ImpresionException $e) {
            $trabajo->estado = 'error';
            $trabajo->error = mb_substr($e->getMessage(), 0, 500);
        }

        $trabajo->save();
    }

    // ── Cola para la tablet (impresoras con conexión «dispositivo») ──

    private const CLAVE_TOMADO = 'impresion:tomado:';
    /** Lo que lleva más tiempo en la cola ya no se imprime solo: se reintenta a mano. */
    public const VIGENCIA_COLA_MINUTOS = 30;
    /** Si la tablet no responde en este tiempo, otra puede tomar el trabajo. */
    private const SEGUNDOS_TOMADO = 45;

    /**
     * La tablet pide lo que tiene que imprimir: trabajos pendientes de impresoras
     * «dispositivo», con los bytes listos para mandar al puerto de la impresora
     * (las Star ya convertidas a imagen). Cada trabajo queda apartado unos
     * segundos para que dos tablets no impriman lo mismo.
     */
    public static function tomarCola(string $dispositivo, int $limite = 10): Collection
    {
        return Cache::lock('impresion:cola', 10)->block(5, function () use ($dispositivo, $limite) {
            $trabajos = TrabajoImpresion::with('impresora')
                ->where('estado', 'pendiente')
                ->where('updated_at', '>=', now()->subMinutes(self::VIGENCIA_COLA_MINUTOS))
                ->whereHas('impresora', fn ($q) => $q->where('conexion', 'dispositivo')->where('activo', true))
                ->orderBy('id')->limit(50)->get()
                ->reject(fn (TrabajoImpresion $t) => Cache::has(self::CLAVE_TOMADO . $t->id))
                ->take($limite)
                ->values();

            return $trabajos->map(function (TrabajoImpresion $t) use ($dispositivo) {
                Cache::put(self::CLAVE_TOMADO . $t->id, $dispositivo, self::SEGUNDOS_TOMADO);
                $impresora = $t->impresora;
                $bytes = base64_decode($t->contenido);
                if ($impresora->tipo_impresion === 'star_raster') {
                    $bytes = StarRaster::desdeEscPos($bytes, $impresora->ancho_caracteres);
                }

                return [
                    'id'        => $t->id,
                    'tipo'      => $t->tipo,
                    'impresora' => $impresora->alias,
                    'ip'        => $impresora->ip_impresora,
                    'puerto'    => (int) ($impresora->puerto ?: 9100),
                    'bytes'     => base64_encode($bytes),
                ];
            });
        });
    }

    /** La tablet avisa si la impresora recibió el trabajo. */
    public static function resultadoDispositivo(TrabajoImpresion $trabajo, bool $ok, ?string $error): TrabajoImpresion
    {
        if ($trabajo->estado === 'impreso') {
            return $trabajo; // otra tablet ya lo imprimió
        }
        $trabajo->intentos++;
        $trabajo->estado = $ok ? 'impreso' : 'error';
        $trabajo->error = $ok ? null : mb_substr($error ?: 'La tablet no pudo imprimir', 0, 500);
        $trabajo->impreso_at = $ok ? now() : null;
        $trabajo->save();
        Cache::forget(self::CLAVE_TOMADO . $trabajo->id);

        return $trabajo;
    }

    private static function transporte(Impresora $impresora): Transporte
    {
        return $impresora->conexion === 'windows' ? app(TransporteWindows::class) : app(TransporteRed::class);
    }
}
