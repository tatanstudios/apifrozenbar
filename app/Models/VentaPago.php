<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VentaPago extends Model
{
    protected $table = 'ventas_pagos';

    protected $fillable = [
        'venta_id',
        'metodo_pago_id',
        'monto',
        'monto_recibido',
        'cambio',
        'referencia',
    ];

    protected $casts = [
        'monto'          => 'decimal:2',
        'monto_recibido' => 'decimal:2',
        'cambio'         => 'decimal:2',
    ];

    public function venta()
    {
        return $this->belongsTo(Venta::class);
    }

    public function metodoPago()
    {
        return $this->belongsTo(MetodoPago::class);
    }
}
