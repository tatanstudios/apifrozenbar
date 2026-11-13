<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdenCompraDetalle extends Model
{
    protected $table = 'ordenes_compra_detalle';

    protected $fillable = [
        'orden_id',
        'producto_id',
        'unidad_medida_id',
        'cantidad',
        'cantidad_recibida',
        'costo_unitario',
        'subtotal',
    ];

    protected $casts = [
        'cantidad' => 'decimal:4',
        'cantidad_recibida' => 'decimal:4',
        'costo_unitario' => 'decimal:4',
        'subtotal' => 'decimal:2',
    ];

    public function orden()
    {
        return $this->belongsTo(OrdenCompra::class, 'orden_id');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function unidad()
    {
        return $this->belongsTo(UnidadesMedida::class, 'unidad_medida_id');
    }

    public function pendiente(): float
    {
        return max(0, round((float) $this->cantidad - (float) $this->cantidad_recibida, 4));
    }
}
