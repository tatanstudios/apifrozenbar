<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Receta extends Model
{
    protected $table = 'recetas';

    protected $fillable = [
        'producto_id',
        'nombre',
        'rendimiento',
        'uso',
        'version',
        'activa',
        'notas',
        'usuario_id',
    ];

    protected $casts = [
        'rendimiento' => 'decimal:4',
        'version' => 'integer',
        'activa' => 'boolean',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function ingredientes()
    {
        return $this->hasMany(RecetaIngrediente::class);
    }

    // Receta que se descuenta al vender el producto (no lleva existencia propia)
    public function esDeVenta(): bool
    {
        return $this->uso === 'venta';
    }

    public function producciones()
    {
        return $this->hasMany(Produccion::class);
    }

    public function usuario()
    {
        return $this->belongsTo(Administrador::class, 'usuario_id');
    }
}
