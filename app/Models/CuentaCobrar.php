<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CuentaCobrar extends Model
{
    protected $table = 'cuentas_cobrar';

    protected $fillable = [
        'cliente_id',
        'venta_id',
        'monto',
        'saldo',
        'fecha_vencimiento',
        'estado',
    ];

    protected $casts = [
        'monto'             => 'decimal:2',
        'saldo'             => 'decimal:2',
        'fecha_vencimiento' => 'date',
    ];

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }

    public function venta()
    {
        return $this->belongsTo(Venta::class);
    }

    public function estaVencida(): bool
    {
        return $this->estado === 'pendiente' && $this->fecha_vencimiento->lt(today());
    }
}
