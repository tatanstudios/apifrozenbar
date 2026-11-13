<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Categoria extends Model
{
    protected $table = 'categorias';

    protected $fillable = [
        'nombre',
        'area_preparacion_id',
        'estado',
    ];

    public function productos()
    {
        return $this->belongsToMany(Producto::class, 'categoria_producto');
    }

    public function area()
    {
        return $this->belongsTo(AreaPreparacion::class, 'area_preparacion_id');
    }
}
