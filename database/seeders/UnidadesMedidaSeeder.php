<?php

namespace Database\Seeders;

use App\Models\UnidadesMedida;
use Illuminate\Database\Seeder;

class UnidadesMedidaSeeder extends Seeder
{
    /**
     * Unidades habituales en un negocio con su equivalencia a la unidad base
     * (unidad → 1, masa → gramos, volumen → mililitros).
     */
    public function run(): void
    {
        $unidades = [
            ['nombre' => 'Unidad',     'abreviatura' => 'u',   'tipo' => 'unidad',  'factor_base' => 1],
            ['nombre' => 'Docena',     'abreviatura' => 'doc', 'tipo' => 'unidad',  'factor_base' => 12],
            ['nombre' => 'Gramo',      'abreviatura' => 'g',   'tipo' => 'masa',    'factor_base' => 1],
            ['nombre' => 'Kilogramo',  'abreviatura' => 'kg',  'tipo' => 'masa',    'factor_base' => 1000],
            ['nombre' => 'Onza',       'abreviatura' => 'oz',  'tipo' => 'masa',    'factor_base' => 28.349523],
            ['nombre' => 'Libra',      'abreviatura' => 'lb',  'tipo' => 'masa',    'factor_base' => 453.59237],
            ['nombre' => 'Arroba',     'abreviatura' => '@',   'tipo' => 'masa',    'factor_base' => 11339.809250], // 25 lb
            ['nombre' => 'Quintal',    'abreviatura' => 'qq',  'tipo' => 'masa',    'factor_base' => 45359.237000], // 100 lb
            ['nombre' => 'Mililitro',  'abreviatura' => 'ml',  'tipo' => 'volumen', 'factor_base' => 1],
            ['nombre' => 'Litro',      'abreviatura' => 'l',   'tipo' => 'volumen', 'factor_base' => 1000],
            ['nombre' => 'Galón',      'abreviatura' => 'gal', 'tipo' => 'volumen', 'factor_base' => 3785.411784],
            ['nombre' => 'Porción',    'abreviatura' => 'porc', 'tipo' => 'unidad', 'factor_base' => 1], // frijoles cocidos, arroz...
        ];

        foreach ($unidades as $u) {
            $unidad = UnidadesMedida::firstOrNew(['nombre' => $u['nombre']]);

            // Si ya existía se respeta la conversión que le hayan configurado;
            // solo se completa si sigue con los valores por defecto (unidad, factor 1)
            $sinConversion = !$unidad->exists
                || ($unidad->tipo === 'unidad' && (float) $unidad->factor_base === 1.0);

            if (!$unidad->exists) {
                $unidad->estado = true;
            }
            $unidad->abreviatura ??= $u['abreviatura'];
            if ($sinConversion) {
                $unidad->tipo        = $u['tipo'];
                $unidad->factor_base = $u['factor_base'];
            }
            $unidad->save();
        }
    }
}
