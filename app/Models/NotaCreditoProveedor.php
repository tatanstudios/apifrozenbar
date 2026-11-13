<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotaCreditoProveedor extends Model
{
    protected $table = 'notas_credito_proveedor';

    protected $fillable = [
        'numero', 'proveedor_id', 'cuenta_pagar_id', 'fecha', 'monto', 'motivo', 'documento',
        'usuario_id', 'anulada', 'anulada_por', 'anulada_at', 'motivo_anulacion',
    ];

    protected $casts = [
        'fecha'      => 'date',
        'monto'      => 'decimal:2',
        'anulada'    => 'boolean',
        'anulada_at' => 'datetime',
    ];

    public function cuenta()
    {
        return $this->belongsTo(CuentaPagar::class, 'cuenta_pagar_id');
    }

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function usuario()
    {
        return $this->belongsTo(Administrador::class, 'usuario_id');
    }
}
