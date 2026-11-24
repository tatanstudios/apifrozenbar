<?php

namespace App\Http\Controllers\Api\Config;

use App\Http\Controllers\Controller;
use App\Models\MovimientoInventario;
use App\Services\AuditoriaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * TEMPORAL — solo para pruebas con el cliente (ventas falsas).
 * Borra ventas, devoluciones, cuentas por cobrar, mesas/comandas, pedidos, todos los
 * turnos de caja (cortes, arqueos, movimientos), compras (órdenes, cuentas por pagar, pagos,
 * notas de crédito), producción, mermas y gastos, y deshace en el inventario lo que movieron.
 * Productos, recetas, clientes, proveedores, entradas/ajustes de inventario y configuración se conservan.
 *
 * Para quitarlo: borrar este archivo, su ruta en routes/api.php (POST /admin/pruebas/borrar-ventas)
 * y el botón «Borrar Ventas» de Configuración → Unidades de Medida.
 */
class BorrarVentasPruebaController extends Controller
{
    // Movimientos de inventario que se deshacen
    private const REFERENCIAS_INVENTARIO = ['venta', 'devolucion', 'compra', 'produccion', 'merma'];

    // Numeración que vuelve a empezar en 1
    private const CORRELATIVOS = [
        'venta', 'devolucion', 'abono', 'orden', 'comanda', 'pedido', 'pedido_especial',
        'compra', 'orden_compra', 'pago_proveedor', 'nota_credito_proveedor', 'produccion', 'gasto',
    ];

    // Tablas que guardan el turno en que se registraron (se conservan, solo se suelta el turno)
    private const CON_TURNO = ['entradas_inventario', 'movimientos_inventario'];

    // En orden: primero las que apuntan a otras
    private const BORRAR = [
        'abonos_detalle', 'abonos', 'cuentas_cobrar',
        'devoluciones_detalle', 'devoluciones',
        'ventas_pagos', 'ventas_detalle', 'ventas',
        'ordenes_items', 'comandas', 'ordenes_tickets', 'ordenes',
        'pedidos_detalle', 'pedidos',
        'pagos_proveedor_detalle', 'pagos_proveedor', 'notas_credito_proveedor', 'cuentas_pagar',
        'compras_detalle', 'compras', 'ordenes_compra_detalle', 'ordenes_compra',
        'producciones_consumos', 'producciones', 'mermas', 'gastos',
        'movimientos_caja', 'arqueos_caja', 'turnos_caja',
    ];

    public function __invoke(Request $request)
    {
        $request->validate(['confirmacion' => 'required|in:BORRAR']);

        if (!$request->user()->hasRole('admin')) {
            return response()->json(['message' => 'Solo el administrador puede borrar las ventas'], 403);
        }

        $resumen = DB::transaction(function () {
            // 1. Devolver al inventario lo que sacaron (o metieron) las ventas y devoluciones
            $movimientos = MovimientoInventario::whereIn('referencia_tipo', self::REFERENCIAS_INVENTARIO)->get();
            $netoPorProducto = [];
            foreach ($movimientos as $m) {
                $signo = in_array($m->tipo, MovimientoInventario::TIPOS_SUMAN, true) ? 1
                    : (in_array($m->tipo, MovimientoInventario::TIPOS_RESTAN, true) ? -1 : 0);
                $netoPorProducto[$m->producto_id] = ($netoPorProducto[$m->producto_id] ?? 0) + $signo * (float) $m->cantidad;
            }
            foreach ($netoPorProducto as $productoId => $neto) {
                if (abs($neto) > 0.00001) {
                    DB::table('inventario')->where('producto_id', $productoId)
                        ->update(['stock_actual' => DB::raw('stock_actual - (' . (float) $neto . ')'), 'updated_at' => now()]);
                }
            }
            MovimientoInventario::whereIn('referencia_tipo', self::REFERENCIAS_INVENTARIO)->delete();

            // 2. Soltar el turno de lo que se conserva
            foreach (self::CON_TURNO as $tabla) {
                DB::table($tabla)->whereNotNull('turno_id')->update(['turno_id' => null]);
            }

            // 3. Borrar ventas, mesas, pedidos y turnos
            $borrados = [];
            foreach (self::BORRAR as $tabla) {
                $borrados[$tabla] = DB::table($tabla)->delete();
            }

            // 4. Nadie debe nada y la numeración empieza de nuevo
            DB::table('clientes')->where('saldo', '!=', 0)->update(['saldo' => 0]);
            DB::table('proveedores')->where('saldo', '!=', 0)->update(['saldo' => 0]);
            DB::table('correlativos')->whereIn('tipo', self::CORRELATIVOS)->update(['siguiente' => 1]);

            return [
                'ventas'            => $borrados['ventas'],
                'turnos'            => $borrados['turnos_caja'],
                'ordenes'           => $borrados['ordenes'],
                'pedidos'           => $borrados['pedidos'],
                'compras'           => $borrados['compras'],
                'producciones'      => $borrados['producciones'],
                'mermas'            => $borrados['mermas'],
                'gastos'            => $borrados['gastos'],
                'productos_stock'   => count($netoPorProducto),
            ];
        });

        AuditoriaService::registrar('configuracion', 'borrar_ventas_prueba', null, null, $resumen,
            "Borrado de ventas de prueba: {$resumen['ventas']} ventas, {$resumen['turnos']} turnos");

        return response()->json([
            'message' => "Listo: se borraron {$resumen['ventas']} ventas, {$resumen['turnos']} turnos de caja, "
                . "{$resumen['ordenes']} cuentas de mesa, {$resumen['pedidos']} pedidos, {$resumen['compras']} compras, "
                . "{$resumen['producciones']} producciones, {$resumen['mermas']} mermas y {$resumen['gastos']} gastos",
            'resumen' => $resumen,
        ]);
    }
}
