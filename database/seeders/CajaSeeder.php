<?php

namespace Database\Seeders;

use App\Models\Caja;
use App\Models\MetodoPago;
use App\Models\Terminal;
use Illuminate\Database\Seeder;

class CajaSeeder extends Seeder
{
    /**
     * Caja principal y métodos de pago habituales.
     */
    public function run(): void
    {
        if (!Caja::query()->exists()) {
            Caja::create(['nombre' => 'Caja principal', 'activo' => true]);
        }

        // La caja de mostrador del mapa de mesas cobra en la caja principal
        Terminal::where('tipo', 'caja')->whereNull('caja_id')->update(['caja_id' => Caja::orderBy('id')->value('id')]);

        $metodos = [
            ['nombre' => 'Efectivo',      'tipo' => 'efectivo',      'afecta_efectivo' => true,  'requiere_referencia' => false, 'orden' => 1, 'activo' => true],
            ['nombre' => 'Tarjeta',       'tipo' => 'tarjeta',       'afecta_efectivo' => false, 'requiere_referencia' => false, 'orden' => 2, 'activo' => true],
            ['nombre' => 'Transferencia', 'tipo' => 'transferencia', 'afecta_efectivo' => false, 'requiere_referencia' => true,  'orden' => 3, 'activo' => true],
            // Venta al crédito a clientes (genera una cuenta por cobrar)
            ['nombre' => 'Crédito',       'tipo' => 'credito',       'afecta_efectivo' => false, 'requiere_referencia' => false, 'orden' => 4, 'activo' => true],
        ];

        foreach ($metodos as $m) {
            MetodoPago::firstOrCreate(['nombre' => $m['nombre']], $m);
        }
    }
}
