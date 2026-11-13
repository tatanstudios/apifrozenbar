<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Models\Administrador;
use App\Models\Cliente;
use App\Models\ConfiguracionNegocio;
use App\Models\MetodoPago;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Modificador;
use App\Models\OrdenItem;
use App\Models\Receta;
use App\Models\TurnoCaja;
use App\Models\Venta;
use App\Models\VentaDetalle;
use App\Models\VentaPago;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class VentaService
{
    /**
     * Cálculo de líneas y totales. Es la única fuente de verdad de los importes:
     * el POS muestra una vista previa con la misma lógica, pero se guarda lo de aquí.
     *
     * @param array $lineas [['producto' => Producto, 'cantidad' => float,
     *                        'descuento_tipo' => 'porcentaje'|'monto'|null, 'descuento_valor' => float], ...]
     * @param array|null $descuentoGlobal ['tipo' => 'porcentaje'|'monto', 'valor' => float]
     */
    public static function calcular(array $lineas, ?array $descuentoGlobal): array
    {
        $resultado = [];
        $bruto = 0.0;
        $descuentoLineas = 0.0;

        foreach ($lineas as $l) {
            /** @var Producto $producto */
            $producto = $l['producto'];
            $cantidad = (float) $l['cantidad'];
            // En ventas sin conexión se respeta el precio que se cobró
            $precio   = (float) ($l['precio'] ?? $producto->precio_venta);
            $importe  = round($precio * $cantidad, 2);

            $descuento = self::descuento($importe, $l['descuento_tipo'] ?? null, (float) ($l['descuento_valor'] ?? 0));

            $resultado[] = [
                'producto'        => $producto,
                'nombre'          => $l['nombre'] ?? $producto->nombre,
                'extras'          => $l['extras'] ?? [],
                'cantidad'        => $cantidad,
                'precio_unitario' => $precio,
                'importe'         => $importe,
                'descuento_tipo'  => $descuento > 0 ? $l['descuento_tipo'] : null,
                'descuento_valor' => $descuento > 0 ? (float) $l['descuento_valor'] : 0,
                'descuento'       => $descuento,
                'subtotal'        => round($importe - $descuento, 2),
                'modificadores'   => $l['modificadores'] ?? [],
                'ajustes'         => $l['ajustes'] ?? [],
            ];

            $bruto += $importe;
            $descuentoLineas += $descuento;
        }

        $subtotal = round($bruto - $descuentoLineas, 2);
        $descGlobal = $descuentoGlobal
            ? self::descuento($subtotal, $descuentoGlobal['tipo'] ?? null, (float) ($descuentoGlobal['valor'] ?? 0))
            : 0.0;

        // The Frozen Bar no cobra IVA: lo que se cobra es el precio menos los descuentos
        $total = round($subtotal - $descGlobal, 2);

        return [
            'lineas'           => $resultado,
            'bruto'            => round($bruto, 2),
            'descuento_lineas' => round($descuentoLineas, 2),
            'subtotal'         => $subtotal,
            'descuento_global' => $descGlobal,
            'descuento_total'  => round($descuentoLineas + $descGlobal, 2),
            'base_imponible'   => $total,
            'impuesto'         => 0.0,
            'total'            => $total,
        ];
    }

    private static function descuento(float $importe, ?string $tipo, float $valor): float
    {
        if (!$tipo || $valor <= 0 || $importe <= 0) {
            return 0.0;
        }

        $descuento = $tipo === 'porcentaje' ? round($importe * min($valor, 100) / 100, 2) : round($valor, 2);

        return min($descuento, $importe);
    }

    /**
     * Recetas de venta activas de los productos de la venta que no llevan existencia propia.
     * @return \Illuminate\Support\Collection<int, Receta> por producto_id
     */
    private static function recetasDeVenta(array $lineas)
    {
        $ids = collect($lineas)->pluck('producto')->reject(fn (Producto $p) => $p->controla_stock)->pluck('id')->unique();
        if ($ids->isEmpty()) {
            return collect();
        }

        return Receta::with(['ingredientes.producto.unidadMedida', 'ingredientes.unidad'])
            ->whereIn('producto_id', $ids)->where('activa', true)->where('uso', 'venta')
            ->get()->keyBy('producto_id');
    }

    /**
     * Saca del inventario lo vendido y devuelve su costo unitario:
     * - un producto con existencia (sándwich, gaseosa) sale él mismo;
     * - uno con receta de venta (limonada, plato, servicio) saca sus insumos;
     * - uno sin existencia ni receta no mueve inventario.
     * Además, cada extra elegido que gasta un insumo (fresa, miel) lo saca.
     * El consumo interno sale con su propio tipo para separarlo de lo vendido.
     */
    private static function descontarInventario(Producto $producto, ?Receta $receta, float $cantidad, string $numero,
        int $ventaId, int $usuarioId, bool $offline, ConfiguracionNegocio $config, array &$conflictos, array $modificadores = [],
        array $ajustes = []): float
    {
        $tipo = $producto->consumo_interno ? 'consumo_interno' : 'venta';
        $porReceta = !$producto->controla_stock && $receta !== null;
        $salidas = match (true) {
            $producto->controla_stock => [['producto_id' => $producto->id, 'cantidad' => $cantidad, 'calculado' => false]],
            $porReceta                => array_map(fn ($s) => [...$s, 'calculado' => true], ProduccionService::consumoDeVenta($receta, $cantidad)),
            default                   => [],
        };
        // Lo que el cliente pidió distinto ("solo 1 huevo", "sin queso"): se descuenta lo que de verdad salió
        if ($porReceta && $ajustes) {
            $salidas = array_values(array_filter(array_map(function ($s) use ($ajustes) {
                if (isset($ajustes[$s['producto_id']])) {
                    $s['cantidad'] = round($s['cantidad'] * (float) $ajustes[$s['producto_id']], 4);
                }
                return $s;
            }, $salidas), fn ($s) => $s['cantidad'] > 0));
        }
        $extras = self::consumoDeModificadores($modificadores, $cantidad);
        if (!$salidas && !$extras) {
            return $porReceta ? 0.0 : (float) $producto->costo_unitario;
        }

        // Sin existencia ni receta, el producto base cuesta lo que diga su ficha
        $costo = ($salidas || $porReceta) ? 0.0 : (float) $producto->costo_unitario * $cantidad;
        $motivo = $receta ? "Venta {$numero}: {$producto->nombre}" : "Venta {$numero}";
        foreach ([...$salidas, ...$extras] as $s) {
            $mov = InventarioService::aplicarMovimiento(
                productoId:     $s['producto_id'],
                tipo:           $tipo,
                cantidad:       $s['cantidad'],
                motivo:         isset($s['extra']) ? "Venta {$numero}: {$s['extra']} en {$producto->nombre}" : $motivo,
                referenciaTipo: 'venta',
                referenciaId:   $ventaId,
                usuarioId:      $usuarioId,
                forzarNegativo: $offline,
                calculado:      $s['calculado'],
            );
            $costo += (float) $mov->costo_unitario * $s['cantidad'];

            if ($offline && (float) $mov->stock_resultante < 0 && !$config->permitir_stock_negativo && !$mov->producto?->permite_sin_existencia) {
                $nombre = $s['calculado'] ? $mov->producto?->nombre . " (para {$producto->nombre})" : $producto->nombre;
                $conflictos[] = "{$nombre}: no había existencia suficiente (quedó en "
                    . rtrim(rtrim(number_format((float) $mov->stock_resultante, 4, '.', ''), '0'), '.') . ')';
            }
        }

        return round($costo / $cantidad, 4);
    }

    /**
     * Insumos que gastan los extras elegidos (Fresa: 30 g por porción), en la unidad del insumo.
     * Se leen de la configuración actual de cada opción, aunque ya esté desactivada.
     */
    public static function consumoDeModificadores(array $ids, float $cantidad): array
    {
        if (!$ids) {
            return [];
        }

        // Un id repetido es la opción pedida varias veces (Huevo extra ×2): gasta el doble
        $veces = array_count_values(array_map('intval', $ids));

        return Modificador::with(['insumo.unidadMedida', 'unidad'])
            ->whereIn('id', array_keys($veces))->whereNotNull('producto_id')->get()
            ->filter(fn (Modificador $m) => $m->insumo?->controla_stock && (float) $m->cantidad > 0)
            ->map(function (Modificador $m) use ($cantidad, $veces) {
                $base = $m->insumo->unidadMedida;
                $porUnidad = $base && $m->unidad ? $m->unidad->convertirA((float) $m->cantidad, $base) : (float) $m->cantidad;

                return [
                    'producto_id' => $m->producto_id,
                    'cantidad'    => round($porUnidad * $cantidad * ($veces[$m->id] ?? 1), 4),
                    'calculado'   => true,
                    'extra'       => $m->nombre,
                ];
            })
            ->filter(fn ($s) => $s['cantidad'] > 0)
            ->values()
            ->all();
    }

    /**
     * Carga y valida los productos de las líneas que vienen del POS.
     * @return array líneas listas para calcular()
     */
    public static function prepararLineas(array $items, bool $offline = false, array &$conflictos = []): array
    {
        // Una venta sin conexión ya ocurrió: se acepta aunque el producto se haya desactivado después
        $productos = Producto::with('unidadMedida')
            ->when($offline, fn ($q) => $q->withTrashed())
            ->whereIn('id', collect($items)->pluck('producto_id'))->get()->keyBy('id');

        return collect($items)->map(function ($item) use ($productos, $offline, &$conflictos) {
            $producto = $productos->get($item['producto_id']);
            $cantidad = (float) $item['cantidad'];

            if (!$producto) {
                throw new NegocioException('Uno de los productos no existe');
            }
            if (!$offline && (!$producto->activo || !$producto->se_vende)) {
                throw new NegocioException('Uno de los productos ya no está disponible para la venta');
            }

            $precio = null;
            $nombre = $item['nombre_orden'] ?? null;
            $modificadores = $item['modificadores_orden'] ?? [];
            $ajustes = $item['ajustes_orden'] ?? [];
            $extras = $item['extras_orden'] ?? []; // para ticket y comanda: un renglón por extra, cambio de receta y nota
            // Venta directa con extras ("azúcar extra") o receta cambiada ("sin huevo"): el precio
            // lleva los extras, el nombre los muestra en ticket y comanda y el inventario baja lo que salió
            $precioEsperado = (float) $producto->precio_venta;
            if (!isset($item['precio_orden']) && (!empty($item['modificadores']) || !empty($item['ajustes']) || !empty($item['notas']))) {
                try {
                    [$elegidos, $extra] = OrdenService::resolverModificadores($producto, $item['modificadores'] ?? []);
                    $resueltos = OrdenService::resolverAjustes($producto, $item['ajustes'] ?? []);
                } catch (NegocioException $e) {
                    // Una venta sin conexión ya ocurrió: si el extra o la receta cambiaron, se registra sin ellos
                    if (!$offline) {
                        throw $e;
                    }
                    $conflictos[] = "{$producto->nombre}: {$e->getMessage()} (se registró sin extras ni cambios de receta)";
                    [$elegidos, $extra, $resueltos] = [[], 0.0, null];
                }
                $linea = new OrdenItem([
                    'nombre_producto' => $producto->nombre,
                    'modificadores'   => $elegidos ?: null,
                    'ajustes'         => $resueltos,
                ]);
                $nota = trim((string) ($item['notas'] ?? ''));
                $nombre = mb_substr($linea->descripcion() . ($nota !== '' ? " · {$nota}" : ''), 0, 300);
                $extras = collect(Modificador::textos($elegidos))->merge($linea->textoAjustes())
                    ->when($nota !== '', fn ($c) => $c->push($nota))->filter()->values()->all();
                $modificadores = Modificador::ids($elegidos);
                $ajustes = $linea->factoresAjuste();
                $precioEsperado = round((float) $producto->precio_venta + $extra, 2);
                $precio = $precioEsperado;
            }
            // Líneas de una orden de mesa: el precio (base + modificadores) lo calculó el servidor
            // al tomar la orden. El cliente no puede enviar estas claves: la validación las descarta.
            if (isset($item['precio_orden'])) {
                $precio = round((float) $item['precio_orden'], 2);
            }
            if ($offline) {
                if (!$producto->activo || !$producto->se_vende || $producto->trashed()) {
                    $conflictos[] = "{$producto->nombre}: ya no estaba a la venta al sincronizar";
                }
                if (isset($item['precio_unitario'])) {
                    $precio = round((float) $item['precio_unitario'], 2);
                    if (abs($precio - $precioEsperado) > 0.001) {
                        $conflictos[] = "{$producto->nombre}: se cobró a $" . number_format($precio, 2)
                            . ' y el precio actual es $' . number_format($precioEsperado, 2);
                    }
                }
            }
            if ($cantidad <= 0) {
                throw new NegocioException("La cantidad de {$producto->nombre} debe ser mayor a 0");
            }
            // El consumo interno no cobra: solo descuenta su receta
            if ($producto->consumo_interno) {
                $precio = 0.0;
            }
            if (!$producto->permite_fraccion && floor($cantidad) != $cantidad) {
                throw new NegocioException("{$producto->nombre} solo se vende por unidades enteras");
            }

            return [
                'producto'        => $producto,
                'nombre'          => $nombre,
                'extras'          => $extras,
                'modificadores'   => $modificadores,
                'ajustes'         => $ajustes,
                'cantidad'        => $cantidad,
                'precio'          => $precio,
                'descuento_tipo'  => $item['descuento_tipo'] ?? null,
                'descuento_valor' => (float) ($item['descuento_valor'] ?? 0),
            ];
        })->all();
    }

    /**
     * Revisa los permisos de los descuentos. Devuelve quién autorizó si superan
     * el máximo configurado (o null si no hizo falta).
     */
    public static function autorizarDescuento(array $calculo, Administrador $usuario, ConfiguracionNegocio $config): ?Administrador
    {
        if ($calculo['descuento_total'] <= 0) {
            return null;
        }

        $porcentaje = $calculo['bruto'] > 0 ? $calculo['descuento_total'] / $calculo['bruto'] * 100 : 0;
        $maximo = (float) $config->descuento_max_sin_autorizacion;

        if ($porcentaje > $maximo + 0.001) {
            return AutorizacionService::autorizar($usuario, 'pos.descuento.autorizar',
                'El descuento (' . round($porcentaje, 1) . "%) supera el máximo de {$maximo}%: necesita autorización del administrador");
        }

        // Dentro del máximo basta el permiso de aplicar descuentos
        if (!$usuario->can('pos.descuento')) {
            return AutorizacionService::autorizar($usuario, 'pos.descuento.autorizar',
                'No tienes permiso para aplicar descuentos: necesita autorización del administrador');
        }

        return null;
    }

    /**
     * Valida los pagos y calcula el cambio. El efectivo puede exceder el total
     * (se da cambio); tarjeta y transferencia no.
     *
     * @return array ['filas' => [[metodo, monto, recibido, cambio, referencia]], 'cambio' => float]
     */
    public static function procesarPagos(array $pagos, float $total): array
    {
        if ($total <= 0) {
            return ['filas' => [], 'cambio' => 0.0, 'credito' => 0.0];
        }

        $metodos = MetodoPago::whereIn('id', collect($pagos)->pluck('metodo_pago_id'))->get()->keyBy('id');

        $efectivoRecibido = 0.0;
        $metodoEfectivo = null;
        $filas = [];

        foreach ($pagos as $p) {
            $metodo = $metodos->get($p['metodo_pago_id']);
            $monto = round((float) $p['monto'], 2);

            if (!$metodo || !$metodo->activo) {
                throw new NegocioException('Una de las formas de pago no existe o está desactivada');
            }
            if ($monto <= 0) {
                continue;
            }
            if ($metodo->requiere_referencia && empty($p['referencia'])) {
                throw new NegocioException("{$metodo->nombre} requiere número de referencia");
            }

            if ($metodo->afecta_efectivo) {
                $efectivoRecibido += $monto;
                $metodoEfectivo ??= $metodo;
            } else {
                $filas[] = ['metodo' => $metodo, 'monto' => $monto, 'recibido' => null, 'cambio' => 0.0, 'referencia' => $p['referencia'] ?? null];
            }
        }

        $otros = round(collect($filas)->sum('monto'), 2);

        if ($otros > $total + 0.001) {
            throw new NegocioException('Los pagos con tarjeta, transferencia o crédito superan el total de la venta');
        }

        $efectivoNecesario = round($total - $otros, 2);

        if ($efectivoRecibido + 0.001 < $efectivoNecesario) {
            throw new NegocioException('Pago incompleto: faltan $' . number_format($efectivoNecesario - $efectivoRecibido, 2));
        }

        $cambio = 0.0;
        if ($efectivoRecibido > 0) {
            if ($efectivoNecesario <= 0) {
                throw new NegocioException('El efectivo no es necesario: el total ya está cubierto con otras formas de pago');
            }
            $cambio = round($efectivoRecibido - $efectivoNecesario, 2);
            array_unshift($filas, [
                'metodo' => $metodoEfectivo, 'monto' => $efectivoNecesario,
                'recibido' => round($efectivoRecibido, 2), 'cambio' => $cambio, 'referencia' => null,
            ]);
        }

        $credito = round(collect($filas)->filter(fn ($f) => $f['metodo']->tipo === 'credito')->sum('monto'), 2);

        return ['filas' => $filas, 'cambio' => $cambio, 'credito' => $credito];
    }

    /**
     * Registra una venta completa en una sola transacción: totales, descuento
     * autorizado, stock, pagos, movimientos de caja y correlativo.
     * Es idempotente por uuid (el POS puede reintentar sin duplicar).
     *
     * $offline = true para ventas hechas sin conexión que llegan al sincronizar:
     * la venta ya ocurrió, así que se respeta el precio cobrado y la hora del
     * dispositivo, y lo que hoy no cuadra (precio cambiado, stock insuficiente,
     * descuento sin autorización válida, turno cerrado) se acepta y queda
     * anotado en "conflictos" para revisión.
     */
    public static function registrar(array $datos, Administrador $usuario, bool $offline = false): Venta
    {
        if ($existente = Venta::where('uuid', $datos['uuid'])->first()) {
            return $existente;
        }

        $config = ConfiguracionNegocio::actual();
        $conflictos = [];
        $lineas = self::prepararLineas($datos['items'], $offline, $conflictos);
        $calculo = self::calcular($lineas, $datos['descuento_global'] ?? null);

        try {
            $autorizador = self::autorizarDescuento($calculo, $usuario, $config);
        } catch (NegocioException $e) {
            if (!$offline) {
                throw $e;
            }
            $autorizador = null;
            $conflictos[] = 'Descuento de $' . number_format($calculo['descuento_total'], 2) . " sin autorización válida: {$e->getMessage()}";
        }

        // Un pedido especial ya trae un anticipo (que entró a caja al recibirlo) y su cliente
        $pedidoPrevio = !empty($datos['pedido_id']) ? Pedido::find($datos['pedido_id']) : null;
        $anticipo = $pedidoPrevio?->tipo === 'especial' ? (float) $pedidoPrevio->anticipo : 0.0;
        if ($anticipo > $calculo['total'] + 0.001) {
            throw new NegocioException('El anticipo ($' . number_format($anticipo, 2) . ') supera el total del pedido: revisa los productos');
        }
        $porCobrar = round($calculo['total'] - $anticipo, 2);

        $pagos = self::procesarPagos($datos['pagos'] ?? [], $porCobrar);

        $cliente = null;
        $clienteId = $datos['cliente_id'] ?? $pedidoPrevio?->cliente_id;
        if ($clienteId) {
            $cliente = Cliente::find($clienteId);
            if (!$cliente || (!$offline && !$cliente->activo)) {
                throw new NegocioException('El cliente no existe o está desactivado');
            }
        }

        $autorizadorCredito = null;
        if ($pagos['credito'] > 0) {
            if (!$cliente) {
                throw new NegocioException('Selecciona el cliente para vender al crédito');
            }
            $autorizadorCredito = CuentaCobrarService::autorizarCredito(
                $cliente, $pagos['credito'], $usuario, $offline, $conflictos
            );
        }

        try {
            return DB::transaction(function () use ($datos, $usuario, $calculo, $autorizador, $pagos, $offline, $config, $cliente, $anticipo, $autorizadorCredito, &$conflictos) {
                $turno = self::turnoParaVenta($datos, $offline, $conflictos);

                $pedido = null;
                if (!empty($datos['pedido_id'])) {
                    $pedido = Pedido::whereKey($datos['pedido_id'])->lockForUpdate()->first();
                    if (!$pedido || $pedido->estado !== 'abierto') {
                        throw new NegocioException($pedido?->tipo === 'especial'
                            ? 'El pedido ya fue entregado o cancelado'
                            : 'La precuenta ya fue cobrada o cancelada');
                    }
                    if (abs(($pedido->tipo === 'especial' ? (float) $pedido->anticipo : 0.0) - $anticipo) > 0.001) {
                        throw new NegocioException('El anticipo del pedido cambió: vuelve a cargarlo');
                    }
                }

                $numero = CorrelativoService::siguiente('venta');

                $venta = Venta::create([
                    'uuid'                     => $datos['uuid'],
                    'numero'                   => $numero,
                    'turno_id'                 => $turno->id,
                    'caja_id'                  => $turno->caja_id,
                    'usuario_id'               => $usuario->id,
                    'pedido_id'                => $pedido?->id,
                    'orden_id'                 => $datos['orden_id'] ?? null,
                    'cliente_id'               => $cliente?->id,
                    'estado'                   => 'completada',
                    'subtotal'                 => $calculo['subtotal'],
                    'descuento_lineas'         => $calculo['descuento_lineas'],
                    'descuento_global'         => $calculo['descuento_global'],
                    'descuento_total'          => $calculo['descuento_total'],
                    'base_imponible'           => $calculo['base_imponible'],
                    'impuesto'                 => $calculo['impuesto'],
                    'total'                    => $calculo['total'],
                    'cambio'                   => $pagos['cambio'],
                    'anticipo_aplicado'        => $anticipo,
                    'origen'                   => $offline ? 'offline' : 'online',
                    // El dispositivo envía la hora en UTC (ISO 8601): se guarda en la hora local del negocio
                    'fecha_venta'              => $offline && !empty($datos['fecha_venta'])
                        ? Carbon::parse($datos['fecha_venta'])->setTimezone(config('app.timezone'))
                        : now(),
                    'sincronizada_at'          => $offline ? now() : null,
                    'numero_provisional'       => $offline ? ($datos['numero_provisional'] ?? null) : null,
                    'descuento_autorizado_por' => $autorizador?->id,
                    'observaciones'            => $datos['observaciones'] ?? null,
                ]);

                // Líneas y salida de inventario (al costo promedio del momento)
                $recetas = self::recetasDeVenta($calculo['lineas']);
                $costoTotal = 0.0;
                foreach ($calculo['lineas'] as $l) {
                    /** @var Producto $producto */
                    $producto = $l['producto'];
                    $costo = self::descontarInventario($producto, $recetas->get($producto->id), $l['cantidad'],
                        $numero, $venta->id, $usuario->id, $offline, $config, $conflictos, $l['modificadores'] ?? [], $l['ajustes'] ?? []);

                    VentaDetalle::create([
                        'venta_id'        => $venta->id,
                        'producto_id'     => $producto->id,
                        'nombre_producto' => $l['nombre'],
                        'extras'          => $l['extras'] ?: null,
                        'unidad'          => $producto->unidadMedida?->abreviatura,
                        'cantidad'        => $l['cantidad'],
                        'precio_unitario' => $l['precio_unitario'],
                        'descuento_tipo'  => $l['descuento_tipo'],
                        'descuento_valor' => $l['descuento_valor'],
                        'descuento'       => $l['descuento'],
                        'subtotal'        => $l['subtotal'],
                        'costo_unitario'  => $costo,
                        'consumo_interno' => $producto->consumo_interno,
                    ]);

                    $costoTotal += $costo * $l['cantidad'];
                }

                $venta->update(['costo_total' => round($costoTotal, 4)]);

                // Pagos y entrada de dinero a la caja
                foreach ($pagos['filas'] as $p) {
                    VentaPago::create([
                        'venta_id'       => $venta->id,
                        'metodo_pago_id' => $p['metodo']->id,
                        'monto'          => $p['monto'],
                        'monto_recibido' => $p['recibido'],
                        'cambio'         => $p['cambio'],
                        'referencia'     => $p['referencia'],
                    ]);

                    CajaService::registrarMovimiento(
                        turno:         $turno,
                        tipo:          'venta',
                        monto:         $p['monto'],
                        concepto:      "Venta {$numero}",
                        usuarioId:     $usuario->id,
                        metodoPagoId:  $p['metodo']->id,
                        referencia:    $p['referencia'],
                        documentoTipo: 'venta',
                        documentoId:   $venta->id,
                    );
                }

                if ($pagos['credito'] > 0) {
                    CuentaCobrarService::crearDesdeVenta($venta, $cliente->id, $pagos['credito'], (bool) $autorizadorCredito, $offline);
                }

                if ($pedido) {
                    $pedido->update(['estado' => 'cobrado', 'venta_id' => $venta->id]);
                }

                if ($conflictos) {
                    $venta->update(['conflictos' => $conflictos]);
                    AuditoriaService::registrar('pos', 'venta_offline_conflicto', $venta, null, ['conflictos' => $conflictos],
                        "Venta {$numero} (sin conexión " . ($datos['numero_provisional'] ?? '') . ')');
                }

                if ($autorizadorCredito) {
                    AuditoriaService::registrar('clientes', 'credito_sobre_limite', $venta, null, [
                        'cliente'        => $cliente->nombre,
                        'credito'        => $pagos['credito'],
                        'disponible'     => $cliente->creditoDisponible(),
                        'autorizado_por' => $autorizadorCredito->nombre,
                    ], "Venta {$numero}");
                }

                if ($autorizador) {
                    AuditoriaService::registrar('pos', 'descuento_autorizado', $venta, null, [
                        'descuento_total' => $calculo['descuento_total'],
                        'bruto'           => $calculo['bruto'],
                        'autorizado_por'  => $autorizador->nombre,
                    ], "Venta {$numero}");
                }

                return $venta;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Dos envíos simultáneos con el mismo uuid: gana el primero
            if ($existente = Venta::where('uuid', $datos['uuid'])->first()) {
                return $existente;
            }
            throw $e;
        }
    }

    /**
     * Turno en el que se registra la venta. En línea: el abierto de la caja.
     * Sin conexión: el turno en el que se vendió si sigue abierto; si ya se
     * cerró, el que esté abierto ahora en la caja (queda anotado). Si no hay
     * ninguno abierto, la venta espera en el dispositivo hasta que se abra.
     */
    private static function turnoParaVenta(array $datos, bool $offline, array &$conflictos): TurnoCaja
    {
        if ($offline && !empty($datos['turno_id'])) {
            $original = TurnoCaja::whereKey($datos['turno_id'])->where('caja_id', $datos['caja_id'])->lockForUpdate()->first();
            if ($original?->estaAbierto()) {
                return $original;
            }
        }

        $turno = TurnoCaja::where('caja_id', $datos['caja_id'])->where('estado', 'abierto')->lockForUpdate()->first();

        if (!$turno) {
            throw new NegocioException($offline
                ? 'No hay turno abierto en la caja: la venta queda pendiente hasta que se abra uno'
                : 'No hay un turno abierto en esta caja. Abre el turno para vender.');
        }

        // En línea se vende en el turno propio; lo vendido sin conexión ya ocurrió y no se rechaza
        if (!$offline) {
            CajaService::exigirTurnoPropio($turno);
        }

        if ($offline && !empty($datos['turno_id']) && (int) $datos['turno_id'] !== $turno->id) {
            $conflictos[] = "El turno #{$datos['turno_id']} ya estaba cerrado: se registró en el turno #{$turno->id}";
        }

        return $turno;
    }

    /**
     * Anula una venta del turno abierto: devuelve el stock, revierte el dinero en
     * caja y deja constancia de quién, por qué y quién autorizó.
     */
    public static function anular(Venta $venta, string $motivo, Administrador $usuario): Venta
    {
        $autorizador = AutorizacionService::autorizar($usuario, 'pos.anular', 'Anular una venta necesita autorización del administrador');

        return DB::transaction(function () use ($venta, $motivo, $usuario, $autorizador) {
            $venta = Venta::whereKey($venta->id)->lockForUpdate()->first();

            if ($venta->estado !== 'completada') {
                throw new NegocioException('La venta ya está anulada o devuelta');
            }
            if ($venta->devoluciones()->exists()) {
                throw new NegocioException('La venta tiene devoluciones: registra otra devolución en lugar de anularla');
            }

            $turno = TurnoCaja::find($venta->turno_id);
            if (!$turno->estaAbierto()) {
                throw new NegocioException('Solo se anulan ventas del turno abierto. Para ventas anteriores registra una devolución.');
            }

            // Devolver el stock de lo que realmente salió del inventario
            $salidas = MovimientoInventario::where('referencia_tipo', 'venta')
                ->where('referencia_id', $venta->id)
                ->whereIn('tipo', ['venta', 'consumo_interno'])
                ->get();

            foreach ($salidas as $salida) {
                InventarioService::aplicarMovimiento(
                    productoId:     $salida->producto_id,
                    tipo:           'anulacion',
                    cantidad:       (float) $salida->cantidad,
                    motivo:         "Anulación de venta {$venta->numero}: {$motivo}",
                    referenciaTipo: 'venta',
                    referenciaId:   $venta->id,
                    usuarioId:      $usuario->id,
                    costoUnitario:  (float) $salida->costo_unitario,
                );
            }

            CuentaCobrarService::anularDeVenta($venta);

            CajaService::anularPorDocumento($turno, 'venta', $venta->id, "Venta {$venta->numero} anulada: {$motivo}", $usuario->id);

            $venta->update([
                'estado'                   => 'anulada',
                'anulada_por'              => $usuario->id,
                'anulacion_autorizada_por' => $autorizador->id,
                'anulada_at'               => now(),
                'motivo_anulacion'         => $motivo,
            ]);

            // Los productos de la mesa vuelven a quedar por cobrar
            OrdenService::alAnularVenta($venta);

            // La precuenta o el pedido especial vuelve a quedar pendiente para cobrarlo de nuevo
            if ($venta->pedido_id) {
                Pedido::whereKey($venta->pedido_id)->update(['estado' => 'abierto', 'venta_id' => null]);
            }

            AuditoriaService::registrar('pos', 'anular_venta', $venta,
                ['estado' => 'completada', 'total' => (float) $venta->total],
                ['estado' => 'anulada', 'autorizado_por' => $autorizador->nombre],
                $motivo);

            return $venta;
        });
    }
}
