<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Models\Administrador;
use App\Models\Devolucion;
use App\Models\DevolucionDetalle;
use App\Models\MetodoPago;
use App\Models\TurnoCaja;
use App\Models\Venta;
use App\Models\VentaDetalle;
use Illuminate\Support\Facades\DB;

class DevolucionService
{
    /**
     * Registra la devolución de productos de una venta (de este u otro turno).
     * El reembolso sale del turno abierto de la caja indicada.
     *
     * @param array $items [['venta_detalle_id' => x, 'cantidad' => y, 'reingresa_inventario' => bool], ...]
     */
    public static function registrar(
        Venta         $venta,
        array         $items,
        string        $motivo,
        int           $cajaId,
        ?int          $metodoPagoId,
        string        $uuid,
        Administrador $usuario,
    ): Devolucion {
        if ($existente = Devolucion::where('uuid', $uuid)->first()) {
            return $existente;
        }

        $autorizador = AutorizacionService::autorizar($usuario, 'pos.devolver', 'Registrar una devolución necesita autorización del administrador');

        return DB::transaction(function () use ($venta, $items, $motivo, $cajaId, $metodoPagoId, $uuid, $usuario, $autorizador) {
            $venta = Venta::whereKey($venta->id)->lockForUpdate()->first();

            if ($venta->estado === 'anulada') {
                throw new NegocioException('La venta está anulada');
            }
            if ($venta->estado === 'devuelta') {
                throw new NegocioException('Todos los productos de esta venta ya fueron devueltos');
            }

            $turno = TurnoCaja::where('caja_id', $cajaId)->where('estado', 'abierto')->first();
            if (!$turno) {
                throw new NegocioException('Abre el turno de caja para registrar el reembolso');
            }
            CajaService::exigirTurnoPropio($turno);

            $metodo = $metodoPagoId ? MetodoPago::find($metodoPagoId) : MetodoPago::efectivo();
            if (!$metodo || !$metodo->activo) {
                throw new NegocioException('Forma de reembolso no válida');
            }
            // "Crédito" como reembolso: se descuenta de lo que el cliente debe de esta venta
            $descuentaCuenta = $metodo->tipo === 'credito';

            // El descuento global se reparte en proporción a cada línea
            $factorGlobal = (float) $venta->subtotal > 0
                ? ((float) $venta->subtotal - (float) $venta->descuento_global) / (float) $venta->subtotal
                : 1.0;

            $detalles = VentaDetalle::where('venta_id', $venta->id)->lockForUpdate()->get()->keyBy('id');
            $lineas = [];
            $total = 0.0;

            foreach ($items as $item) {
                $detalle  = $detalles->get($item['venta_detalle_id']);
                $cantidad = (float) $item['cantidad'];

                if (!$detalle) {
                    throw new NegocioException('Uno de los productos no pertenece a esta venta');
                }
                if ($cantidad <= 0) {
                    continue;
                }
                if ($cantidad > $detalle->cantidadDisponibleParaDevolver() + 0.00001) {
                    throw new NegocioException(
                        "{$detalle->nombre_producto}: solo se pueden devolver " . rtrim(rtrim(number_format($detalle->cantidadDisponibleParaDevolver(), 4, '.', ''), '0'), '.')
                    );
                }

                // Precio realmente cobrado por unidad (con descuento de línea y global)
                $precioEfectivo = (float) $detalle->subtotal / (float) $detalle->cantidad * $factorGlobal;
                $subtotal = round($precioEfectivo * $cantidad, 2);

                $lineas[] = [$detalle, $cantidad, $precioEfectivo, $subtotal, (bool) ($item['reingresa_inventario'] ?? true)];
                $total += $subtotal;
            }

            if (!$lineas) {
                throw new NegocioException('Indica al menos un producto y su cantidad a devolver');
            }

            $total = round($total, 2);
            $numero = CorrelativoService::siguiente('devolucion', 'DEV');

            $devolucion = Devolucion::create([
                'uuid'           => $uuid,
                'numero'         => $numero,
                'venta_id'       => $venta->id,
                'turno_id'       => $turno->id,
                'usuario_id'     => $usuario->id,
                'autorizado_por' => $autorizador->id,
                'metodo_pago_id' => $metodo->id,
                'motivo'         => $motivo,
                'total'          => $total,
            ]);

            foreach ($lineas as [$detalle, $cantidad, $precioEfectivo, $subtotal, $reingresa]) {
                DevolucionDetalle::create([
                    'devolucion_id'        => $devolucion->id,
                    'venta_detalle_id'     => $detalle->id,
                    'producto_id'          => $detalle->producto_id,
                    'cantidad'             => $cantidad,
                    'precio_unitario'      => round($precioEfectivo, 4),
                    'subtotal'             => $subtotal,
                    'reingresa_inventario' => $reingresa,
                ]);

                $detalle->increment('cantidad_devuelta', $cantidad);

                // Lo que vuelve en buen estado regresa al stock a su costo original;
                // lo dañado no reingresa (se registrará como merma en Producción)
                if ($reingresa && $detalle->producto->controla_stock) {
                    InventarioService::aplicarMovimiento(
                        productoId:     $detalle->producto_id,
                        tipo:           'devolucion',
                        cantidad:       $cantidad,
                        motivo:         "Devolución {$numero} de venta {$venta->numero}: {$motivo}",
                        referenciaTipo: 'devolucion',
                        referenciaId:   $devolucion->id,
                        usuarioId:      $usuario->id,
                        costoUnitario:  (float) $detalle->costo_unitario,
                    );
                }
            }

            if ($total > 0 && $descuentaCuenta) {
                CuentaCobrarService::aplicarDevolucion($venta, $total);
            }

            if ($total > 0) {
                CajaService::registrarMovimiento(
                    turno:         $turno,
                    tipo:          'devolucion',
                    monto:         $total,
                    concepto:      "Devolución {$numero} (venta {$venta->numero})",
                    usuarioId:     $usuario->id,
                    metodoPagoId:  $metodo->id,
                    referencia:    $metodo->requiere_referencia ? "Venta {$venta->numero}" : null,
                    documentoTipo: 'devolucion',
                    documentoId:   $devolucion->id,
                );
            }

            // Si ya se devolvió todo, la venta queda marcada como devuelta
            $pendiente = VentaDetalle::where('venta_id', $venta->id)
                ->whereColumn('cantidad_devuelta', '<', 'cantidad')
                ->exists();
            if (!$pendiente) {
                $venta->update(['estado' => 'devuelta']);
            }

            AuditoriaService::registrar('pos', 'devolucion', $devolucion, null, [
                'venta'          => $venta->numero,
                'total'          => $total,
                'autorizado_por' => $autorizador->nombre,
                'items'          => $items,
            ], $motivo);

            return $devolucion;
        });
    }
}
