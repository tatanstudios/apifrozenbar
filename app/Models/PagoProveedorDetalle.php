<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PagoProveedorDetalle extends Model
{
    protected $table = 'pagos_proveedor_detalle';

    protected $fillable = [
        'pago_id',
        'cuenta_pagar_id',
        'monto',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
    ];

    public function pago()
    {
        return $this->belongsTo(PagoProveedor::class, 'pago_id');
    }

    public function cuenta()
    {
        return $this->belongsTo(CuentaPagar::class, 'cuenta_pagar_id');
    }
}
