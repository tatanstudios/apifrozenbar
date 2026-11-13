<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tarjeta de modificadores («Bebidas con leche»): sus bloques en orden (Tipo de leche, Azúcar,
 * Extras) y los productos que la llevan. Un producto lleva una sola tarjeta.
 */
class ConjuntoModificador extends Model
{
    protected $table = 'conjuntos_modificadores';

    protected $fillable = ['nombre', 'activo'];

    protected $casts = ['activo' => 'boolean'];

    public function bloques()
    {
        return $this->hasMany(GrupoModificador::class, 'conjunto_id')->where('activo', true)->orderBy('orden')->orderBy('id');
    }

    public function productos()
    {
        return $this->belongsToMany(Producto::class, 'conjunto_producto', 'conjunto_id', 'producto_id');
    }
}
