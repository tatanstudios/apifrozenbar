<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Models\Administrador;
use App\Models\ConfiguracionNegocio;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use Illuminate\Support\Facades\DB;

class PedidoService
{
    /**
     * Crea o reemplaza una precuenta (venta en espera). No mueve stock ni caja:
     * eso ocurre al cobrarla. Idempotente por uuid al crear.
     */
    public static function guardar(array $datos, Administrador $usuario, ?Pedido $pedido = null): Pedido
    {
        if (!$pedido && ($existente = Pedido::where('uuid', $datos['uuid'])->first())) {
            return $existente;
        }

        $lineas  = VentaService::prepararLineas($datos['items']);
        $calculo = VentaService::calcular($lineas, null);

        return DB::transaction(function () use ($datos, $usuario, $pedido, $calculo) {
            if ($pedido) {
                $pedido = Pedido::whereKey($pedido->id)->lockForUpdate()->first();
                if ($pedido->tipo !== 'precuenta' || $pedido->estado !== 'abierto') {
                    throw new NegocioException('La precuenta ya fue cobrada o cancelada');
                }
                $pedido->update([
                    'nombre'        => $datos['nombre'],
                    'observaciones' => $datos['observaciones'] ?? null,
                    'total'         => $calculo['total'],
                ]);
                $pedido->detalles()->delete();
            } else {
                $pedido = Pedido::create([
                    'uuid'          => $datos['uuid'],
                    'numero'        => CorrelativoService::siguiente('pedido', 'P'),
                    'tipo'          => 'precuenta',
                    'nombre'        => $datos['nombre'],
                    'caja_id'       => $datos['caja_id'] ?? null,
                    'usuario_id'    => $usuario->id,
                    'estado'        => 'abierto',
                    'total'         => $calculo['total'],
                    'observaciones' => $datos['observaciones'] ?? null,
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

            return $pedido;
        });
    }

    public static function cancelar(Pedido $pedido, string $motivo, Administrador $usuario): Pedido
    {
        return DB::transaction(function () use ($pedido, $motivo, $usuario) {
            $pedido = Pedido::whereKey($pedido->id)->lockForUpdate()->first();

            if ($pedido->tipo !== 'precuenta' || $pedido->estado !== 'abierto') {
                throw new NegocioException('La precuenta ya fue cobrada o cancelada');
            }

            $pedido->update([
                'estado'             => 'cancelado',
                'cancelado_por'      => $usuario->id,
                'motivo_cancelacion' => $motivo,
            ]);

            AuditoriaService::registrar('pos', 'cancelar_precuenta', $pedido, null, ['total' => (float) $pedido->total], $motivo);

            return $pedido;
        });
    }
}
