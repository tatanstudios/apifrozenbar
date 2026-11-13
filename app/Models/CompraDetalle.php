<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompraDetalle extends Model
{
    protected $table = 'compras_detalle';

    protected $fillable = [
        'compra_id',
        'producto_id',
        'orden_detalle_id',
        'unidad_medida_id',
        'cantidad',
        'costo_unitario',
        'subtotal',
        'cantidad_base',
        'costo_base',
    ];

    protected $casts = [
        'cantidad' => 'decimal:4',
        'costo_unitario' => 'decimal:4',
        'subtotal' => 'decimal:2',
        'cantidad_base' => 'decimal:4',
        'costo_base' => 'decimal:4',
    ];

    public function compra()
    {
        return $this->belongsTo(Compra::class);
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
