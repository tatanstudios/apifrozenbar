<?php

namespace App\Models;

use App\Models\Concerns\RegistraTurno;
use Illuminate\Database\Eloquent\Model;

class Pedido extends Model
{
    use RegistraTurno;

    protected $table = 'pedidos';

    protected $fillable = [
        'uuid',
        'numero',
        'tipo',
        'nombre',
        'telefono',
        'cliente_id',
        'caja_id',
        'usuario_id',
        'estado',
        'total',
        'anticipo',
        'fecha_entrega',
        'venta_id',
        'observaciones',
        'cancelado_por',
        'motivo_cancelacion',
    ];

    protected $casts = [
        'total'         => 'decimal:2',
        'anticipo'      => 'decimal:2',
        'fecha_entrega' => 'datetime',
    ];

    public function detalles()
    {
        return $this->hasMany(PedidoDetalle::class);
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }

    public function caja()
    {
        return $this->belongsTo(Caja::class);
    }

    public function usuario()
    {
        return $this->belongsTo(Administrador::class, 'usuario_id');
    }

    public function venta()
    {
        return $this->belongsTo(Venta::class);
    }
}
