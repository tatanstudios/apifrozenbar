<?php

namespace App\Models;

use App\Exceptions\NegocioException;
use Illuminate\Database\Eloquent\Model;

class UnidadesMedida extends Model
{
    protected $table = 'unidades_medida';

    const TIPOS = ['unidad', 'masa', 'volumen'];

    protected $fillable = [
        'nombre',
        'abreviatura',
        'tipo',
        'factor_base',
        'estado',
    ];

    protected $casts = [
        'factor_base' => 'decimal:6',
        'estado'      => 'boolean',
    ];

    /**
     * Convierte una cantidad de esta unidad a otra del mismo tipo.
     * Ej.: 2 lb → g = 907.18474. Lo usarán recetas y compras.
     */
    public function convertirA(float $cantidad, UnidadesMedida $destino): float
    {
        if ($this->id === $destino->id) {
            return $cantidad;
        }

        if ($this->tipo !== $destino->tipo) {
            throw new NegocioException(
                "No se puede convertir de {$this->nombre} ({$this->tipo}) a {$destino->nombre} ({$destino->tipo})"
            );
        }

        return $cantidad * (float) $this->factor_base / (float) $destino->factor_base;
    }
}
