<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecetaIngrediente extends Model
{
    protected $table = 'recetas_ingredientes';

    protected $fillable = [
        'receta_id',
        'producto_id',
        'unidad_medida_id',
        'cantidad',
        'merma_porcentaje',
    ];

    protected $casts = [
        'cantidad' => 'decimal:4',
        'merma_porcentaje' => 'decimal:2',
    ];

    public function receta()
    {
        return $this->belongsTo(Receta::class);
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function unidad()
    {
        return $this->belongsTo(UnidadesMedida::class, 'unidad_medida_id');
    }
}
