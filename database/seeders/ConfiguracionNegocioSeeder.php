<?php

namespace Database\Seeders;

use App\Models\ConfiguracionNegocio;
use App\Models\Correlativo;
use Illuminate\Database\Seeder;

class ConfiguracionNegocioSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (!ConfiguracionNegocio::query()->exists()) {
            ConfiguracionNegocio::create([
                'nombre'                         => 'The Frozen Bar',
                'moneda'                         => 'USD',
                'simbolo_moneda'                 => '$',
                'permitir_stock_negativo'        => true,
                'descuento_max_sin_autorizacion' => 10,
                'mensaje_ticket'                 => '¡Gracias por su compra!',
            ]);
        }

        $correlativos = [
            ['tipo' => 'venta',        'prefijo' => ''],  // el ticket es solo número: 000123
            ['tipo' => 'devolucion',   'prefijo' => 'DEV'],
            ['tipo' => 'orden_compra', 'prefijo' => 'OC'],
            ['tipo' => 'compra',       'prefijo' => 'C'],
            ['tipo' => 'produccion',   'prefijo' => 'PR'],
            ['tipo' => 'pedido',       'prefijo' => 'P'],
        ];

        foreach ($correlativos as $c) {
            Correlativo::firstOrCreate(['tipo' => $c['tipo']], ['prefijo' => $c['prefijo'], 'siguiente' => 1, 'relleno' => 6]);
        }
    }
}
