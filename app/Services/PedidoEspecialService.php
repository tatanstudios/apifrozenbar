<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Models\Administrador;
use App\Models\Cliente;
use App\Models\ConfiguracionNegocio;
use App\Models\MetodoPago;
use App\Models\MovimientoCaja;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\TurnoCaja;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pedidos especiales (pasteles por encargo): se registran con fecha de entrega
 * y, si el cliente deja un anticipo, ese dinero entra a caja en el momento.
 * Al entregarlo se cobra desde el POS y el anticipo se descuenta del total.
 */
class PedidoEspecialService
{
    /**
     * Crea o edita el pedido. Al crearlo se puede recibir el anticipo inicial.
     * Idempotente por uuid al crear.
     */
    public static function guardar(array $datos, Administrador $usuario, ?Pedido $pedido = null): Pedido
    {
        if (!$pedido && ($existente = Pedido::where('uuid', $datos['uuid'])->first())) {
            return $existente;
        }

        $lineas  = VentaService::prepararLineas($datos['items']);
        $calculo = VentaService::calcular($lineas, null);

        $entrega = Carbon::parse($datos['fecha_entrega']);
        if (!$pedido && $entrega->lt(now()->startOfDay())) {
            throw new NegocioException('La fecha de entrega no puede ser anterior a hoy');
        }

        $cliente = !empty($datos['cliente_id']) ? Cliente::find($datos['cliente_id']) : null;
        if (!empty($datos['cliente_id']) && (!$cliente || !$cliente->activo)) {
            throw new NegocioException('El cliente no existe o está desactivado');
        }

        return DB::transaction(function () use ($datos, $usuario, $pedido, $calculo, $entrega, $cliente) {
            $campos = [
                'nombre'        => $datos['nombre'] ?? $cliente?->nombre,
                'telefono'      => $datos['telefono'] ?? $cliente?->telefono,
                'cliente_id'    => $cliente?->id,
                'fecha_entrega' => $entrega,
                'total'         => $calculo['total'],
                'observaciones' => $datos['observaciones'] ?? null,
            ];
            if (empty($campos['nombre'])) {
                throw new NegocioException('Indica el nombre de quien hace el pedido');
            }

            if ($pedido) {
                $pedido = Pedido::whereKey($pedido->id)->lockForUpdate()->first();
                if ($pedido->tipo !== 'especial' || $pedido->estado !== 'abierto') {
                    throw new NegocioException('El pedido ya fue entregado o cancelado');
                }
                if ((float) $pedido->anticipo > $calculo['total'] + 0.001) {
                    throw new NegocioException('El total no puede quedar por debajo del anticipo recibido ($'
                        . number_format((float) $pedido->anticipo, 2) . ')');
                }
                $antes = ['total' => (float) $pedido->total, 'fecha_entrega' => $pedido->fecha_entrega?->format('Y-m-d H:i')];
                $pedido->update($campos);
                $pedido->detalles()->delete();
                AuditoriaService::registrar('pedidos', 'editar_pedido', $pedido, $antes,
                    ['total' => $calculo['total'], 'fecha_entrega' => $entrega->format('Y-m-d H:i')], "Pedido {$pedido->numero}");
            } else {
                $pedido = Pedido::create([
                    ...$campos,
                    'uuid'       => $datos['uuid'],
                    'numero'     => CorrelativoService::siguiente('pedido_especial', 'PE'),
                    'tipo'       => 'especial',
                    'caja_id'    => $datos['caja_id'] ?? null,
                    'usuario_id' => $usuario->id,
                    'estado'     => 'abierto',
                ]);
            }

            foreach ($calculo['lineas'] as $i => $l) {
                PedidoDetalle::create([
                    'pedido_id'       => $pedido->id,
                    'producto_id'     => $l['producto']->id,
                    'nombre_producto' => $l['producto']->nombre,
                    'cantidad'        => $l['cantidad'],
                    'precio_unitario' => $l['precio_unitario'],
                    'descuento_tipo'  => $l['descuento_tipo'],
                    'descuento_valor' => $l['descuento_valor'],
                    'notas'           => $datos['items'][$i]['notas'] ?? null,
                ]);
            }

            if ($pedido->wasRecentlyCreated && !empty($datos['anticipo']) && (float) $datos['anticipo'] > 0) {
                self::aplicarAnticipo($pedido, (float) $datos['anticipo'], $datos, $usuario);
            }

            return $pedido;
        });
    }

    /**
     * Recibe un anticipo (o un abono adicional) del pedido: entra a la caja
     * del turno abierto con el tipo "anticipo".
     */
    public static function registrarAnticipo(Pedido $pedido, float $monto, array $datos, Administrador $usuario): Pedido
    {
        return DB::transaction(function () use ($pedido, $monto, $datos, $usuario) {
            $pedido = Pedido::whereKey($pedido->id)->lockForUpdate()->first();
            if ($pedido->tipo !== 'especial' || $pedido->estado !== 'abierto') {
                throw new NegocioException('El pedido ya fue entregado o cancelado');
            }

            self::aplicarAnticipo($pedido, $monto, $datos, $usuario);

            return $pedido;
        });
    }

    private static function aplicarAnticipo(Pedido $pedido, float $monto, array $datos, Administrador $usuario): void
    {
        $monto = round($monto, 2);
        $nuevo = round((float) $pedido->anticipo + $monto, 2);

        if ($monto <= 0) {
            throw new NegocioException('El anticipo debe ser mayor a 0');
        }
        if ($nuevo > (float) $pedido->total + 0.001) {
            throw new NegocioException('El anticipo no puede superar el total del pedido ($' . number_format((float) $pedido->total, 2) . ')');
        }

        $metodo = !empty($datos['metodo_pago_id']) ? MetodoPago::find($datos['metodo_pago_id']) : MetodoPago::efectivo();
        if (!$metodo || !$metodo->activo || $metodo->tipo === 'credito') {
            throw new NegocioException('Forma de pago no válida para el anticipo');
        }

        $turno = TurnoCaja::where('caja_id', $datos['caja_id'] ?? 0)->where('estado', 'abierto')->first();
        if (!$turno) {
            throw new NegocioException('Abre el turno de caja para recibir el anticipo');
        }
        CajaService::exigirTurnoPropio($turno);

        CajaService::registrarMovimiento(
            turno:         $turno,
            tipo:          'anticipo',
            monto:         $monto,
            concepto:      "Anticipo pedido {$pedido->numero} ({$pedido->nombre})",
            usuarioId:     $usuario->id,
            metodoPagoId:  $metodo->id,
            referencia:    $datos['referencia'] ?? null,
            documentoTipo: 'pedido',
            documentoId:   $pedido->id,
        );

        $pedido->update(['anticipo' => $nuevo]);

        AuditoriaService::registrar('pedidos', 'anticipo', $pedido, null,
            ['monto' => $monto, 'anticipo_total' => $nuevo, 'metodo' => $metodo->nombre], "Pedido {$pedido->numero}");
    }

    /**
     * Cancela el pedido. Si se devuelve el anticipo, sale de la caja del turno
     * abierto con la forma de pago indicada; si no, el anticipo queda en caja
     * (el cliente lo perdió según la política del negocio).
     */
    public static function cancelar(Pedido $pedido, string $motivo, bool $devolverAnticipo, ?int $cajaId, ?int $metodoPagoId, Administrador $usuario): Pedido
    {
        return DB::transaction(function () use ($pedido, $motivo, $devolverAnticipo, $cajaId, $metodoPagoId, $usuario) {
            $pedido = Pedido::whereKey($pedido->id)->lockForUpdate()->first();

            if ($pedido->tipo !== 'especial' || $pedido->estado !== 'abierto') {
                throw new NegocioException('El pedido ya fue entregado o cancelado');
            }

            $anticipo = (float) $pedido->anticipo;
            if ($devolverAnticipo && $anticipo > 0) {
                $turno = TurnoCaja::where('caja_id', $cajaId ?? 0)->where('estado', 'abierto')->first();
                if (!$turno) {
                    throw new NegocioException('Abre el turno de caja para devolver el anticipo');
                }
                $metodo = $metodoPagoId ? MetodoPago::find($metodoPagoId) : MetodoPago::efectivo();
                if (!$metodo || !$metodo->activo || $metodo->tipo === 'credito') {
                    throw new NegocioException('Forma de devolución no válida');
                }

                CajaService::registrarMovimiento(
                    turno:         $turno,
                    tipo:          'devolucion',
                    monto:         $anticipo,
                    concepto:      "Devolución de anticipo, pedido {$pedido->numero} cancelado",
                    usuarioId:     $usuario->id,
                    metodoPagoId:  $metodo->id,
                    referencia:    $metodo->requiere_referencia ? "Pedido {$pedido->numero}" : null,
                    documentoTipo: 'pedido',
                    documentoId:   $pedido->id,
                );
            }

            $pedido->update([
                'estado'             => 'cancelado',
                'cancelado_por'      => $usuario->id,
                'motivo_cancelacion' => $motivo,
            ]);

            AuditoriaService::registrar('pedidos', 'cancelar_pedido', $pedido, null, [
                'total'             => (float) $pedido->total,
                'anticipo'          => $anticipo,
                'anticipo_devuelto' => $devolverAnticipo && $anticipo > 0,
            ], $motivo);

            return $pedido;
        });
    }

    // Movimientos de caja del anticipo (para mostrarlos en el detalle)
    public static function anticipos(Pedido $pedido)
    {
        return MovimientoCaja::with('metodoPago:id,nombre')
            ->where('documento_tipo', 'pedido')->where('documento_id', $pedido->id)
            ->where('anulado', false)
            ->orderBy('id')->get();
    }
}
