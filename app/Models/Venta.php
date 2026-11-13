<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Venta extends Model
{
    protected $table = 'ventas';

    protected $fillable = [
        'uuid',
        'numero',
        'numero_provisional',
        'turno_id',
        'caja_id',
        'usuario_id',
        'cliente_id',
        'pedido_id',
        'orden_id',
        'estado',
        'subtotal',
        'descuento_lineas',
        'descuento_global',
        'descuento_total',
        'base_imponible',
        'impuesto',
        'total',
        'costo_total',
        'cambio',
        'anticipo_aplicado',
        'origen',
        'fecha_venta',
        'sincronizada_at',
        'conflictos',
        'descuento_autorizado_por',
        'observaciones',
        'anulada_por',
        'anulacion_autorizada_por',
        'anulada_at',
        'motivo_anulacion',
    ];

    protected $casts = [
        'subtotal'         => 'decimal:2',
        'descuento_lineas' => 'decimal:2',
        'descuento_global' => 'decimal:2',
        'descuento_total'  => 'decimal:2',
        'base_imponible'   => 'decimal:2',
        'impuesto'         => 'decimal:2',
        'total'            => 'decimal:2',
        'costo_total'      => 'decimal:4',
        'cambio'           => 'decimal:2',
        'anticipo_aplicado' => 'decimal:2',
        'fecha_venta'      => 'datetime',
        'sincronizada_at'  => 'datetime',
        'conflictos'       => 'array',
        'anulada_at'       => 'datetime',
    ];

    public function detalles()
    {
        return $this->hasMany(VentaDetalle::class);
    }

    public function pagos()
    {
        return $this->hasMany(VentaPago::class);
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }

    public function cuentaCobrar()
    {
        return $this->hasOne(CuentaCobrar::class);
    }

    public function devoluciones()
    {
        return $this->hasMany(Devolucion::class);
    }

    public function turno()
    {
        return $this->belongsTo(TurnoCaja::class, 'turno_id');
    }

    public function caja()
    {
        return $this->belongsTo(Caja::class);
    }

    public function usuario()
    {
        return $this->belongsTo(Administrador::class, 'usuario_id');
    }

    public function pedido()
    {
        return $this->belongsTo(Pedido::class);
    }

    public function anuladaPor()
    {
        return $this->belongsTo(Administrador::class, 'anulada_por');
    }

    public function anulacionAutorizadaPor()
    {
        return $this->belongsTo(Administrador::class, 'anulacion_autorizada_por');
    }

    public function descuentoAutorizadoPor()
    {
        return $this->belongsTo(Administrador::class, 'descuento_autorizado_por');
    }
}
