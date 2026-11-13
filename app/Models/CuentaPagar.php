<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CuentaPagar extends Model
{
    protected $table = 'cuentas_pagar';

    protected $fillable = [
        'proveedor_id',
        'compra_id',
        'monto',
        'saldo',
        'fecha_vencimiento',
        'estado',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'saldo' => 'decimal:2',
        'fecha_vencimiento' => 'date',
    ];

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function compra()
    {
        return $this->belongsTo(Compra::class);
    }

    public function notasCredito()
    {
        return $this->hasMany(NotaCreditoProveedor::class, 'cuenta_pagar_id');
    }

    public function estaVencida(): bool
    {
        return $this->estado === 'pendiente' && $this->fecha_vencimiento->lt(today());
    }
}
