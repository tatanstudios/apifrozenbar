<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deja la base lista para empezar a operar: borra todo lo que se generó usando el
 * sistema (ventas, turnos, mesas, compras, gastos, producción, kárdex, auditoría…)
 * y conserva la configuración y el catálogo (productos, recetas, categorías, mesas,
 * extras, impresoras, usuarios, roles, cajas, métodos de pago, clientes y proveedores).
 *
 * Las existencias quedan en 0 (se conservan los mínimos) para cargar el conteo
 * inicial, los saldos de clientes y proveedores en 0 y los correlativos en 1.
 * Antes de borrar saca un respaldo con pos:respaldo.
 */
class LimpiarMovimientos extends Command
{
    protected $signature = 'pos:limpiar-movimientos {--force : No pedir confirmación}';

    protected $description = 'Borra los movimientos de prueba y conserva el catálogo y la configuración';

    /** Lo que se genera al operar. El orden no importa: se borra sin revisar llaves foráneas. */
    public const TABLAS = [
        'ventas', 'ventas_detalle', 'ventas_pagos', 'devoluciones', 'devoluciones_detalle',
        'turnos_caja', 'movimientos_caja', 'arqueos_caja',
        'ordenes', 'ordenes_tickets', 'ordenes_items', 'comandas',
        'pedidos', 'pedidos_detalle',
        'abonos', 'abonos_detalle', 'cuentas_cobrar',
        'compras', 'compras_detalle', 'ordenes_compra', 'ordenes_compra_detalle',
        'cuentas_pagar', 'pagos_proveedor', 'pagos_proveedor_detalle', 'notas_credito_proveedor',
        'gastos',
        'producciones', 'producciones_consumos', 'mermas',
        'entradas_inventario', 'entradas_inventario_detalle', 'movimientos_inventario',
        'trabajos_impresion', 'notificaciones', 'auditorias',
        'personal_access_tokens', 'password_reset_tokens', 'jobs', 'failed_jobs',
    ];

    public function handle(): int
    {
        $this->warn('Se borrarán ventas, turnos, mesas, compras, gastos, producción, mermas, kárdex, notificaciones y auditoría.');
        $this->line('Se conservan productos, recetas, categorías, mesas, extras, impresoras, usuarios, clientes y proveedores.');
        $this->line('Las existencias quedan en 0 y todas las sesiones se cierran.');

        if (!$this->option('force') && !$this->confirm('¿Continuar?')) {
            return self::FAILURE;
        }

        if (Artisan::call('pos:respaldo', ['--nombre' => 'antes_de_limpiar']) !== self::SUCCESS) {
            $this->error('No se limpió nada porque falló el respaldo: ' . trim(Artisan::output()));
            return self::FAILURE;
        }
        $this->line(trim(Artisan::output()));

        Schema::disableForeignKeyConstraints();
        try {
            foreach (self::TABLAS as $tabla) {
                if (Schema::hasTable($tabla)) {
                    DB::table($tabla)->truncate();
                }
            }
            DB::table('inventario')->update(['stock_actual' => 0, 'updated_at' => now()]);
            DB::table('clientes')->update(['saldo' => 0]);
            DB::table('proveedores')->update(['saldo' => 0]);
            DB::table('correlativos')->update(['siguiente' => 1, 'updated_at' => now()]);
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        // Claves de idempotencia y bloqueos de la tablet (pedidos de mostrador, etc.)
        Cache::flush();

        $this->info('Listo. Carga el conteo inicial desde Inventario → Registrar (tipo ajuste o entrada).');

        return self::SUCCESS;
    }
}
