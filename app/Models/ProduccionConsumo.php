<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProduccionConsumo extends Model
{
    protected $table = 'producciones_consumos';

    protected $fillable = [
        'produccion_id',
        'producto_id',
        'cantidad_teorica',
        'cantidad_real',
        'costo_unitario',
        'costo_total',
    ];

    protected $casts = [
        'cantidad_teorica' => 'decimal:4',
        'cantidad_real' => 'decimal:4',
        'costo_unitario' => 'decimal:4',
        'costo_total' => 'decimal:4',
    ];

    public function produccion()
    {
        return $this->belongsTo(Produccion::class);
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }
}
