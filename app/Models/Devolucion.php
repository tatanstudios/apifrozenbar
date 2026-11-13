<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Devolucion extends Model
{
    protected $table = 'devoluciones';

    protected $fillable = [
        'uuid',
        'numero',
        'venta_id',
        'turno_id',
        'usuario_id',
        'autorizado_por',
        'metodo_pago_id',
        'motivo',
        'total',
    ];

    protected $casts = [
        'total' => 'decimal:2',
    ];

    public function venta()
    {
        return $this->belongsTo(Venta::class);
    }

    public function detalles()
    {
        return $this->hasMany(DevolucionDetalle::class);
    }

    public function usuario()
    {
        return $this->belongsTo(Administrador::class, 'usuario_id');
    }

    public function autorizadoPor()
    {
        return $this->belongsTo(Administrador::class, 'autorizado_por');
    }

    public function metodoPago()
    {
        return $this->belongsTo(MetodoPago::class);
    }
}
