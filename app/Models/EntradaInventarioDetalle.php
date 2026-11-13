<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EntradaInventarioDetalle extends Model
{
    protected $table = 'entradas_inventario_detalle';

    protected $fillable = [
        'entrada_id',
        'producto_id',
        'cantidad',
        'stock_anterior',
        'stock_resultante',
    ];

    protected $casts = [
        'cantidad'         => 'decimal:4',
        'stock_anterior'   => 'decimal:4',
        'stock_resultante' => 'decimal:4',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function entrada()
    {
        return $this->belongsTo(EntradaInventario::class, 'entrada_id');
    }
}
