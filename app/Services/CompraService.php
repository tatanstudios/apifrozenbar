<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Models\Administrador;
use App\Models\Compra;
use App\Models\CompraDetalle;
use App\Models\CuentaPagar;
use App\Models\MovimientoInventario;
use App\Models\OrdenCompra;
use App\Models\OrdenCompraDetalle;
use App\Models\PagoProveedor;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\UnidadesMedida;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Órdenes de compra y compras (recepción de mercadería).
 * Una compra mete el producto al inventario a su costo (que actualiza el costo
 * promedio) y crea la cuenta por pagar; si es de contado se paga en el acto.
 */
class CompraService
{
    /**
     * Valida y calcula las líneas: producto que se compra, unidad de compra del
     * mismo tipo que la del producto (quintal → libras) y su conversión.
     *
     * @param array $items [['producto_id', 'unidad_medida_id'?, 'cantidad', 'costo_unitario', 'orden_detalle_id'?], ...]
     */
    public static function prepararLineas(array $items): array
    {
        $productos = Producto::with('unidadMedida')->whereIn('id', collect($items)->pluck('producto_id'))->get()->keyBy('id');
        $unidades = UnidadesMedida::whereIn('id', collect($items)->pluck('unidad_medida_id')->filter())->get()->keyBy('id');

        return collect($items)->map(function ($item) use ($productos, $unidades) {
            $producto = $productos->get($item['producto_id']);
            if (!$producto || !$producto->activo) {
                throw new NegocioException('Uno de los productos no existe o está desactivado');
            }

            $cantidad = round((float) $item['cantidad'], 4);
            $costo = round((float) $item['costo_unitario'], 4);
            if ($cantidad <= 0) {
                throw new NegocioException("La cantidad de {$producto->nombre} debe ser mayor a 0");
            }
            if ($costo < 0) {
                throw new NegocioException("El costo de {$producto->nombre} no puede ser negativo");
            }

            $base = $producto->unidadMedida;
            $unidad = !empty($item['unidad_medida_id']) ? $unidades->get($item['unidad_medida_id']) : $base;
            if (!$unidad) {
                throw new NegocioException("Indica la unidad de compra de {$producto->nombre}");
            }

            $cantidadBase = $base ? round($unidad->convertirA($cantidad, $base), 4) : $cantidad;
            if ($producto->controla_stock && !$producto->permite_fraccion && abs($cantidadBase - round($cantidadBase)) > 0.0001) {
                throw new NegocioException("{$producto->nombre} se maneja en unidades enteras: {$cantidad} {$unidad->nombre} no da una cantidad exacta");
            }
            if (!$producto->permite_fraccion) {
                $cantidadBase = round($cantidadBase);
            }

            $subtotal = round($cantidad * $costo, 2);

            return [
                'producto'         => $producto,
                'unidad'           => $unidad,
                'cantidad'         => $cantidad,
                'costo_unitario'   => $costo,
                'subtotal'         => $subtotal,
                'cantidad_base'    => $cantidadBase,
                'costo_base'       => $cantidadBase > 0 ? round($subtotal / $cantidadBase, 4) : 0,
                'orden_detalle_id' => $item['orden_detalle_id'] ?? null,
            ];
        })->all();
    }

    // ── Órdenes de compra ─────────────────────────────────────

    public static function guardarOrden(array $datos, Administrador $usuario, ?OrdenCompra $orden = null): OrdenCompra
    {
        $proveedor = Proveedor::find($datos['proveedor_id']);
        if (!$proveedor || !$proveedor->activo) {
            throw new NegocioException('El proveedor no existe o está desactivado');
        }
        $lineas = self::prepararLineas($datos['items']);
        $total = round(collect($lineas)->sum('subtotal'), 2);

        return DB::transaction(function () use ($datos, $usuario, $orden, $lineas, $total) {
            $campos = [
                'proveedor_id'   => $datos['proveedor_id'],
                'fecha'          => $datos['fecha'] ?? today(),
                'fecha_esperada' => $datos['fecha_esperada'] ?? null,
                'observaciones'  => $datos['observaciones'] ?? null,
                'total'          => $total,
            ];

            if ($orden) {
                $orden = OrdenCompra::whereKey($orden->id)->lockForUpdate()->first();
                if (!in_array($orden->estado, ['borrador', 'enviada'], true)) {
                    throw new NegocioException('Solo se editan órdenes en borrador o enviadas sin recepciones');
                }
                $orden->update($campos);
                $orden->detalles()->delete();
            } else {
                $orden = OrdenCompra::create([
                    ...$campos,
                    'numero'     => CorrelativoService::siguiente('orden_compra', 'OC'),
                    'estado'     => 'borrador',
                    'usuario_id' => $usuario->id,
                ]);
            }

            foreach ($lineas as $l) {
                OrdenCompraDetalle::create([
                    'orden_id'         => $orden->id,
                    'producto_id'      => $l['producto']->id,
                    'unidad_medida_id' => $l['unidad']->id,
                    'cantidad'         => $l['cantidad'],
                    'costo_unitario'   => $l['costo_unitario'],
                    'subtotal'         => $l['subtotal'],
                ]);
            }

            return $orden;
        });
    }

    // borrador → enviada; cancelar (sin recepciones); cerrar una parcial (no se recibirá el resto)
    public static function cambiarEstadoOrden(OrdenCompra $orden, string $accion, Administrador $usuario, ?string $motivo = null): OrdenCompra
    {
        return DB::transaction(function () use ($orden, $accion, $usuario, $motivo) {
            $orden = OrdenCompra::whereKey($orden->id)->lockForUpdate()->first();
            $antes = $orden->estado;

            match ($accion) {
                'enviar' => $orden->estado === 'borrador'
                    ? $orden->update(['estado' => 'enviada'])
                    : throw new NegocioException('Solo se envían órdenes en borrador'),
                'cancelar' => in_array($orden->estado, ['borrador', 'enviada'], true)
                    ? $orden->update(['estado' => 'cancelada', 'cancelada_por' => $usuario->id, 'motivo_cancelacion' => $motivo])
                    : throw new NegocioException($orden->estado === 'parcial'
                        ? 'La orden ya tiene recepciones: ciérrala en lugar de cancelarla'
                        : 'La orden ya está recibida o cancelada'),
                'cerrar' => $orden->estado === 'parcial'
                    ? $orden->update(['estado' => 'recibida', 'motivo_cancelacion' => $motivo])
                    : throw new NegocioException('Solo se cierran órdenes recibidas en parte'),
                default => throw new NegocioException('Acción no válida'),
            };

            AuditoriaService::registrar('compras', "orden_{$accion}", $orden, ['estado' => $antes], ['estado' => $orden->estado], $motivo ?? "Orden {$orden->numero}");

            return $orden;
        });
    }

    private static function actualizarEstadoOrden(OrdenCompra $orden): void
    {
        $detalles = OrdenCompraDetalle::where('orden_id', $orden->id)->get();
        $recibido = $detalles->sum(fn ($d) => (float) $d->cantidad_recibida);
        $completa = $detalles->every(fn ($d) => (float) $d->cantidad_recibida + 0.0001 >= (float) $d->cantidad);

        $orden->update(['estado' => match (true) {
            $completa      => 'recibida',
            $recibido > 0  => 'parcial',
            default        => 'enviada',
        }]);
    }

    // ── Compras ───────────────────────────────────────────────

    /**
     * Registra una compra: inventario, costo promedio, cuenta por pagar y, si
     * es de contado, el pago (desde caja o fuera de caja). Idempotente por uuid.
     *
     * $datos['pago'] (contado): ['origen' => 'caja'|'externo', 'caja_id', 'metodo_pago_id', 'referencia']
     */
    public static function registrar(array $datos, Administrador $usuario): Compra
    {
        if ($existente = Compra::where('uuid', $datos['uuid'])->first()) {
            return $existente;
        }

        $proveedor = Proveedor::find($datos['proveedor_id']);
        if (!$proveedor || !$proveedor->activo) {
            throw new NegocioException('El proveedor no existe o está desactivado');
        }

        $fecha = Carbon::parse($datos['fecha']);
        if ($fecha->gt(today())) {
            throw new NegocioException('La fecha de la compra no puede ser futura');
        }

        $lineas = self::prepararLineas($datos['items']);
        $subtotal = round(collect($lineas)->sum('subtotal'), 2);
        $impuesto = round((float) ($datos['impuesto'] ?? 0), 2);
        $total = round($subtotal + $impuesto, 2);
        if ($total <= 0) {
            throw new NegocioException('El total de la compra debe ser mayor a 0');
        }

        if (!empty($datos['numero_documento'])) {
            $repetida = Compra::where('proveedor_id', $proveedor->id)
                ->where('tipo_documento', $datos['tipo_documento'] ?? 'factura')
                ->where('numero_documento', $datos['numero_documento'])
                ->first();
            if ($repetida) {
                throw new NegocioException("Ese documento ya está registrado en la compra {$repetida->numero}");
            }
        }

        try {
            return DB::transaction(function () use ($datos, $usuario, $proveedor, $fecha, $lineas, $subtotal, $impuesto, $total) {
                $orden = null;
                if (!empty($datos['orden_compra_id'])) {
                    $orden = OrdenCompra::whereKey($datos['orden_compra_id'])->lockForUpdate()->first();
                    if (!$orden || $orden->proveedor_id !== $proveedor->id) {
                        throw new NegocioException('La orden de compra no es de este proveedor');
                    }
                    if (!in_array($orden->estado, ['enviada', 'parcial', 'borrador'], true)) {
                        throw new NegocioException("La orden {$orden->numero} ya está recibida o cancelada");
                    }
                }

                $numero = CorrelativoService::siguiente('compra', 'C');

                $compra = Compra::create([
                    'uuid'             => $datos['uuid'],
                    'numero'           => $numero,
                    'proveedor_id'     => $proveedor->id,
                    'orden_compra_id'  => $orden?->id,
                    'fecha'            => $fecha->toDateString(),
                    'tipo_documento'   => $datos['tipo_documento'] ?? 'factura',
                    'numero_documento' => $datos['numero_documento'] ?? null,
                    'condicion'        => $datos['condicion'],
                    'subtotal'         => $subtotal,
                    'impuesto'         => $impuesto,
                    'total'            => $total,
                    'estado'           => 'registrada',
                    'observaciones'    => $datos['observaciones'] ?? null,
                    'usuario_id'       => $usuario->id,
                ]);

                foreach ($lineas as $l) {
                    /** @var Producto $producto */
                    $producto = $l['producto'];

                    if ($l['orden_detalle_id']) {
                        $detalleOrden = OrdenCompraDetalle::whereKey($l['orden_detalle_id'])->where('orden_id', $orden?->id)->lockForUpdate()->first();
                        if (!$detalleOrden || $detalleOrden->producto_id !== $producto->id) {
                            throw new NegocioException("{$producto->nombre} no corresponde a la línea de la orden");
                        }
                        // Lo recibido se lleva en la unidad de la orden
                        $recibido = $l['unidad']->convertirA($l['cantidad'], $detalleOrden->unidad);
                        $detalleOrden->increment('cantidad_recibida', round($recibido, 4));
                    }

                    CompraDetalle::create([
                        'compra_id'        => $compra->id,
                        'producto_id'      => $producto->id,
                        'orden_detalle_id' => $l['orden_detalle_id'],
                        'unidad_medida_id' => $l['unidad']->id,
                        'cantidad'         => $l['cantidad'],
                        'costo_unitario'   => $l['costo_unitario'],
                        'subtotal'         => $l['subtotal'],
                        'cantidad_base'    => $l['cantidad_base'],
                        'costo_base'       => $l['costo_base'],
                    ]);

                    if ($producto->controla_stock) {
                        InventarioService::aplicarMovimiento(
                            productoId:     $producto->id,
                            tipo:           'compra',
                            cantidad:       $l['cantidad_base'],
                            motivo:         "Compra {$numero} a {$proveedor->nombre}",
                            referenciaTipo: 'compra',
                            referenciaId:   $compra->id,
                            usuarioId:      $usuario->id,
                            costoUnitario:  $l['costo_base'],
                        );
                    } else {
                        // Sin inventario: el costo es el de la última compra
                        $producto->update(['costo_unitario' => $l['costo_base']]);
                    }
                }

                if ($orden) {
                    self::actualizarEstadoOrden($orden);
                }

                // Toda compra genera su cuenta; la de contado se paga de inmediato
                $vence = !empty($datos['fecha_vencimiento'])
                    ? Carbon::parse($datos['fecha_vencimiento'])
                    : $fecha->copy()->addDays($datos['condicion'] === 'credito' ? $proveedor->dias_credito : 0);

                CuentaPagar::create([
                    'proveedor_id'      => $proveedor->id,
                    'compra_id'         => $compra->id,
                    'monto'             => $total,
                    'saldo'             => $total,
                    'fecha_vencimiento' => $vence->toDateString(),
                    'estado'            => 'pendiente',
                ]);
                $proveedor = Proveedor::whereKey($proveedor->id)->lockForUpdate()->first();
                $proveedor->update(['saldo' => round((float) $proveedor->saldo + $total, 2)]);

                if ($datos['condicion'] === 'contado') {
                    CuentaPagarService::registrarPago($proveedor, [
                        ...($datos['pago'] ?? []),
                        'uuid'         => (string) Str::uuid(),
                        'monto'        => $total,
                        'fecha'        => $fecha->toDateString(),
                        'aplicaciones' => [['compra_id' => $compra->id, 'monto' => $total]],
                    ], $usuario, "Compra {$numero}");
                }

                AuditoriaService::registrar('compras', 'compra', $compra, null, [
                    'proveedor' => $proveedor->nombre,
                    'total'     => $total,
                    'condicion' => $datos['condicion'],
                    'documento' => trim(($datos['tipo_documento'] ?? 'factura') . ' ' . ($datos['numero_documento'] ?? '')),
                ], "Compra {$numero}");

                return $compra;
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($existente = Compra::where('uuid', $datos['uuid'])->first()) {
                return $existente;
            }
            throw new NegocioException('Ese documento ya está registrado para este proveedor');
        }
    }

    /**
     * Anula una compra: saca del inventario lo que entró y anula su cuenta.
     * Si ya tiene pagos, primero hay que anularlos.
     */
    public static function anular(Compra $compra, string $motivo, Administrador $usuario): Compra
    {
        return DB::transaction(function () use ($compra, $motivo, $usuario) {
            $compra = Compra::whereKey($compra->id)->lockForUpdate()->first();
            if ($compra->estado === 'anulada') {
                throw new NegocioException('La compra ya está anulada');
            }

            $cuenta = CuentaPagar::where('compra_id', $compra->id)->lockForUpdate()->first();

            // El pago de una compra de contado se anula con ella; otros pagos se anulan aparte
            if ($cuenta) {
                $pagos = PagoProveedor::where('anulado', false)
                    ->whereHas('detalles', fn ($q) => $q->where('cuenta_pagar_id', $cuenta->id))
                    ->get();
                foreach ($pagos as $pago) {
                    if ($pago->detalles()->where('cuenta_pagar_id', '!=', $cuenta->id)->exists() || $compra->condicion !== 'contado') {
                        throw new NegocioException("La compra tiene pagos registrados ({$pago->numero}): anula primero los pagos");
                    }
                    CuentaPagarService::anularPago($pago, "Anulación de compra {$compra->numero}: {$motivo}", $usuario);
                }
                $cuenta->refresh();
            }

            $entradas = MovimientoInventario::where('referencia_tipo', 'compra')
                ->where('referencia_id', $compra->id)->where('tipo', 'compra')->get();
            foreach ($entradas as $entrada) {
                InventarioService::aplicarMovimiento(
                    productoId:     $entrada->producto_id,
                    tipo:           'salida',
                    cantidad:       (float) $entrada->cantidad,
                    motivo:         "Anulación de compra {$compra->numero}: {$motivo}",
                    referenciaTipo: 'compra',
                    referenciaId:   $compra->id,
                    usuarioId:      $usuario->id,
                );
            }

            foreach ($compra->detalles()->whereNotNull('orden_detalle_id')->get() as $d) {
                $detalleOrden = OrdenCompraDetalle::whereKey($d->orden_detalle_id)->lockForUpdate()->first();
                $devuelto = $d->unidad->convertirA((float) $d->cantidad, $detalleOrden->unidad);
                $detalleOrden->update(['cantidad_recibida' => max(0, round((float) $detalleOrden->cantidad_recibida - $devuelto, 4))]);
            }
            if ($compra->orden_compra_id) {
                $orden = OrdenCompra::whereKey($compra->orden_compra_id)->lockForUpdate()->first();
                if ($orden->estado !== 'cancelada') {
                    self::actualizarEstadoOrden($orden);
                }
            }

            if ($cuenta) {
                $proveedor = Proveedor::whereKey($compra->proveedor_id)->lockForUpdate()->first();
                $proveedor->update(['saldo' => round(max(0, (float) $proveedor->saldo - (float) $cuenta->saldo), 2)]);
                $cuenta->update(['estado' => 'anulada', 'saldo' => 0]);
            }

            $compra->update([
                'estado'           => 'anulada',
                'anulada_por'      => $usuario->id,
                'anulada_at'       => now(),
                'motivo_anulacion' => $motivo,
            ]);

            AuditoriaService::registrar('compras', 'anular_compra', $compra,
                ['estado' => 'registrada', 'total' => (float) $compra->total], ['estado' => 'anulada'], $motivo);

            return $compra;
        });
    }
}
