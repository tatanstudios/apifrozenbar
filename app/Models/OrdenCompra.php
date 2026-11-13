<?php

namespace App\Models;

use App\Models\Concerns\RegistraTurno;
use Illuminate\Database\Eloquent\Model;

class OrdenCompra extends Model
{
    use RegistraTurno;

    protected $table = 'ordenes_compra';

    protected $fillable = [
        'numero',
        'proveedor_id',
        'fecha',
        'fecha_esperada',
        'estado',
        'total',
        'observaciones',
        'usuario_id',
        'cancelada_por',
        'motivo_cancelacion',
    ];

    protected $casts = [
        'fecha' => 'date',
        'fecha_esperada' => 'date',
        'total' => 'decimal:2',
    ];

    public function detalles()
    {
        return $this->hasMany(OrdenCompraDetalle::class, 'orden_id');
    }

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function usuario()
    {
        return $this->belongsTo(Administrador::class, 'usuario_id');
    }

    public function compras()
    {
        return $this->hasMany(Compra::class, 'orden_compra_id');
    }
}
