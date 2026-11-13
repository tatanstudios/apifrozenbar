<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DevolucionDetalle extends Model
{
    protected $table = 'devoluciones_detalle';

    protected $fillable = [
        'devolucion_id',
        'venta_detalle_id',
        'producto_id',
        'cantidad',
        'precio_unitario',
        'subtotal',
        'reingresa_inventario',
    ];

    protected $casts = [
        'cantidad'             => 'decimal:4',
        'precio_unitario'      => 'decimal:4',
        'subtotal'             => 'decimal:2',
        'reingresa_inventario' => 'boolean',
    ];

    public function devolucion()
    {
        return $this->belongsTo(Devolucion::class);
    }

    public function ventaDetalle()
    {
        return $this->belongsTo(VentaDetalle::class);
    }
}
